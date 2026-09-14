// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv
//
// Setzt die Netzsperre fuer die Plugin-Kennungen.
//
// Ein Plugin laeuft im Netz des Anwendungscontainers. Ohne Sperre erreichte es
// die Datenbank mit jedem Konto, das es erraten kann, die Mails, die ganze
// Anwendung auf localhost, andere Dienste im Docker-Netz und in einer Cloud
// die Metadaten mit den Zugangsdaten der Maschine. Mit Sperre erreicht es den
// Core nur ueber dessen Schnittstelle, die Datenbank nur auf ihrem Port und
// das Internet nur, wenn ein Administrator dem ausdruecklich zugestimmt hat.
//
// Die Regeln selbst stehen in plugin-firewall.sh. Dieses Programm tut nur, was
// ein Shell-Skript nicht kann: es haelt CAP_NET_ADMIN als Dateifaehigkeit,
// prueft die Argumente streng, leert die Umgebung und reicht die Faehigkeit an
// das Skript weiter. Ausfuehren duerfen es nur Mitglieder der Gruppe
// `immobase` (Rechte 0750), also nicht die Plugins selbst.
//
//   plugin-firewall <core-port> <db-port> <db-ipv4,...> [internet-kennung ...]

#define _GNU_SOURCE
#include <arpa/inet.h>
#include <errno.h>
#include <stdio.h>
#include <stdlib.h>
#include <string.h>
#include <linux/capability.h>
#include <sys/prctl.h>
#include <sys/syscall.h>
#include <unistd.h>

#define SCRIPT "/usr/local/libexec/immobase/plugin-firewall.sh"
#define PLUGIN_UID_MIN 101024L
#define PLUGIN_UID_MAX 165535L
#define MOST_ADDRESSES 8
#define MOST_INTERNET 256

static void refuse(const char *what)
{
    fprintf(stderr, "plugin-firewall: %s\n", what);
    _exit(2);
}

static long number(const char *text, long min, long max, const char *what)
{
    char *end = NULL;
    errno = 0;
    long value = strtol(text, &end, 10);

    if (errno != 0 || end == text || *end != '\0' || value < min || value > max) {
        refuse(what);
    }

    return value;
}

static void addresses(const char *list)
{
    char copy[256];

    if (strlen(list) == 0 || strlen(list) >= sizeof copy) {
        refuse("ungueltige Datenbankadressen");
    }

    strcpy(copy, list);
    int count = 0;

    for (char *save = NULL, *part = strtok_r(copy, ",", &save); part != NULL; part = strtok_r(NULL, ",", &save)) {
        struct in_addr parsed;

        if (++count > MOST_ADDRESSES || inet_pton(AF_INET, part, &parsed) != 1) {
            refuse("ungueltige Datenbankadresse");
        }
    }
}

// Die Faehigkeit ueber exec hinaus behalten: erst erben lassen, dann als
// umgebende Faehigkeit anheben. Ohne sie verloere das Skript sie beim Start.
static void pass_on_net_admin(void)
{
    struct __user_cap_header_struct header = { _LINUX_CAPABILITY_VERSION_3, 0 };
    struct __user_cap_data_struct data[2];

    if (syscall(SYS_capget, &header, data) != 0) {
        perror("plugin-firewall: capget");
        _exit(111);
    }

    data[0].inheritable = data[0].permitted & (1U << CAP_NET_ADMIN);
    data[1].inheritable = 0;

    if (syscall(SYS_capset, &header, data) != 0
        || prctl(PR_CAP_AMBIENT, PR_CAP_AMBIENT_RAISE, CAP_NET_ADMIN, 0, 0) != 0) {
        fputs("plugin-firewall: CAP_NET_ADMIN fehlt — der Container braucht cap_add: NET_ADMIN\n", stderr);
        _exit(111);
    }
}

int main(int argc, char **argv)
{
    if (argc < 4 || argc > 4 + MOST_INTERNET) {
        refuse("Aufruf: plugin-firewall <core-port> <db-port> <db-ipv4,...> [internet-kennung ...]");
    }

    number(argv[1], 1, 65535, "ungueltiger Port des Cores");
    number(argv[2], 1, 65535, "ungueltiger Port der Datenbank");
    addresses(argv[3]);

    for (int i = 4; i < argc; i++) {
        number(argv[i], PLUGIN_UID_MIN, PLUGIN_UID_MAX, "Kennung ausserhalb des Plugin-Bereichs");
    }

    if (clearenv() != 0 || setenv("PATH", "/usr/sbin:/usr/bin:/sbin:/bin", 1) != 0) {
        perror("plugin-firewall: env");
        return 111;
    }

    pass_on_net_admin();

    char **args = calloc((size_t) argc + 2, sizeof *args);

    if (args == NULL) {
        return 111;
    }

    args[0] = "sh";
    args[1] = SCRIPT;

    for (int i = 1; i < argc; i++) {
        args[i + 1] = argv[i];
    }

    execv("/bin/sh", args);
    perror("plugin-firewall: exec");
    return 127;
}
