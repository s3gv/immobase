// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv
//
// Startet und beendet Plugin-Prozesse, jedes Plugin unter einer eigenen
// Kennung und nur hinter der Netzsperre aus plugin-firewall.
//
// Der Core laeuft als `immobase`. Liefe ein Plugin unter demselben Benutzer,
// laese es aus /proc die Umgebung des Cores — Datenbankzugang, APP_SECRET —
// und koennte dessen Prozesse beenden. Liefen alle Plugins unter einem
// gemeinsamen Benutzer, laese eines das Token des anderen und kaeme damit an
// dessen Daten. Deshalb bekommt jedes seine eigene Kennung.
//
// Zum Wechseln braucht es CAP_SETUID und CAP_SETGID. Beides bekommt dieses
// Programm und nichts sonst. Es wechselt nur in einen festen Bereich von
// Kennungen, weit weg von root und von allem, was im Image einen Namen hat.
// Ein allgemeines Werkzeug wie gosu oder setpriv koennte mit denselben
// Faehigkeiten auch zu root wechseln. Ausfuehren duerfen es nur Mitglieder der
// Gruppe `immobase` (Rechte 0750), also nicht die Plugins selbst.
//
//   run-as-plugin exec <kennung> <programm> [argumente ...]
//   run-as-plugin kill <kennung> <pid>

#define _GNU_SOURCE
#include <arpa/inet.h>
#include <errno.h>
#include <grp.h>
#include <signal.h>
#include <stdio.h>
#include <stdlib.h>
#include <string.h>
#include <linux/capability.h>
#include <netinet/in.h>
#include <sys/prctl.h>
#include <sys/socket.h>
#include <sys/stat.h>
#include <sys/syscall.h>
#include <sys/types.h>
#include <unistd.h>

// Der Core vergibt 100000 + Port; Ports unter 1024 kommen nicht vor.
#define PLUGIN_UID_MIN 101024L
#define PLUGIN_UID_MAX 165535L

static void fail(const char *what)
{
    perror(what);
    _exit(111);
}

static uid_t parse_uid(const char *text)
{
    char *end = NULL;
    errno = 0;
    long value = strtol(text, &end, 10);

    if (errno != 0 || end == text || *end != '\0' || value < PLUGIN_UID_MIN || value > PLUGIN_UID_MAX) {
        fputs("run-as-plugin: Kennung ausserhalb des Plugin-Bereichs\n", stderr);
        _exit(2);
    }

    return (uid_t) value;
}

static void become(uid_t uid)
{
    gid_t gid = (gid_t) uid;

    if (setgroups(0, NULL) != 0 || setresgid(gid, gid, gid) != 0 || setresuid(uid, uid, uid) != 0) {
        fail("run-as-plugin");
    }

    // Alle Faehigkeiten abgeben. Ein Wechsel zwischen zwei Kennungen ungleich
    // root raeumt sie nicht von selbst ab — mit CAP_SETUID in der Hand liesse
    // sich sonst gleich wieder zu root wechseln.
    struct __user_cap_header_struct header = { _LINUX_CAPABILITY_VERSION_3, 0 };
    struct __user_cap_data_struct data[2];
    memset(data, 0, sizeof data);

    if (syscall(SYS_capset, &header, data) != 0) {
        fail("run-as-plugin: capset");
    }

    // Und keine neuen: ein Programm mit eigenen Faehigkeiten, das das Plugin
    // startet, bekommt sie nicht.
    if (prctl(PR_SET_NO_NEW_PRIVS, 1, 0, 0, 0) != 0) {
        fail("run-as-plugin: no_new_privs");
    }

    if (getuid() != uid || geteuid() != uid || getgid() != gid || setuid(0) == 0) {
        fputs("run-as-plugin: Rechte wurden nicht abgegeben\n", stderr);
        _exit(111);
    }
}

// Das Zuhause des Plugins in /tmp, nur fuer es selbst. Liegt dort schon etwas
// unter diesem Namen, das nicht ihm gehoert — ein anderes Plugin koennte es
// vorher angelegt haben —, startet es nicht.
static void prepare_home(uid_t uid)
{
    char home[64];
    snprintf(home, sizeof home, "/tmp/immobase-plugin-%u", (unsigned) uid);

    if (mkdir(home, 0700) != 0 && errno != EEXIST) {
        fail("run-as-plugin: mkdir");
    }

    struct stat found;

    if (lstat(home, &found) != 0 || !S_ISDIR(found.st_mode) || found.st_uid != uid || (found.st_mode & 077) != 0) {
        fputs("run-as-plugin: das Zuhause des Plugins gehoert jemand anderem\n", stderr);
        _exit(111);
    }

    if (setenv("HOME", home, 1) != 0 || setenv("XDG_DATA_HOME", home, 1) != 0 || setenv("XDG_CONFIG_HOME", home, 1) != 0) {
        fail("run-as-plugin: setenv");
    }
}

// Ohne Netzsperre startet kein Plugin. Die Sperre setzt plugin-firewall; ob
// sie greift, zeigt ein Verbindungsversuch unter der Kennung des Plugins:
// gesperrt kommt sofort „No route to host", ungesperrt „Connection refused".
// Lieber ein Plugin, das nicht laeuft, als eines, das alles erreicht.
static void require_network_lock(void)
{
    int probe = socket(AF_INET, SOCK_STREAM, 0);

    if (probe < 0) {
        fail("run-as-plugin: socket");
    }

    struct sockaddr_in closed = { .sin_family = AF_INET, .sin_port = htons(1) };
    closed.sin_addr.s_addr = htonl(INADDR_LOOPBACK);

    int connected = connect(probe, (struct sockaddr *) &closed, sizeof closed);
    int reason = errno;
    close(probe);

    if (connected == 0 || reason != EHOSTUNREACH) {
        fputs("run-as-plugin: die Netzsperre fuer Plugins fehlt — das Plugin startet nicht\n", stderr);
        _exit(112);
    }
}

int main(int argc, char **argv)
{
    if (argc >= 4 && strcmp(argv[1], "exec") == 0) {
        uid_t uid = parse_uid(argv[2]);
        become(uid);
        require_network_lock();
        prepare_home(uid);
        umask(077);
        execvp(argv[3], argv + 3);
        fail("run-as-plugin: exec");
    }

    if (argc == 4 && strcmp(argv[1], "kill") == 0) {
        uid_t uid = parse_uid(argv[2]);
        char *end = NULL;
        long pid = strtol(argv[3], &end, 10);

        if (end == argv[3] || *end != '\0' || pid <= 1) {
            fputs("run-as-plugin: ungueltige Prozesskennung\n", stderr);
            return 2;
        }

        become(uid);

        // Unter der Kennung des Plugins trifft das Signal nur dessen Prozesse.
        if (kill((pid_t) pid, SIGTERM) != 0) {
            perror("run-as-plugin: kill");
            return 1;
        }

        return 0;
    }

    fputs("Aufruf: run-as-plugin exec <kennung> <programm> [argumente ...] | run-as-plugin kill <kennung> <pid>\n", stderr);
    return 2;
}
