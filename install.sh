#!/usr/bin/env bash
# SPDX-License-Identifier: AGPL-3.0-or-later
# SPDX-FileCopyrightText: 2026 s3gv
#
# Erstinstallation von ImmoBase.
#
# Fragt ab, was eine Installation braucht, schreibt .env.local, baut das Image,
# startet die Dienste und legt das erste Konto an. Am Ende ist ImmoBase
# benutzbar — ohne weitere Handgriffe.
#
# Keine externe Gegenstelle: kein Lizenzserver, kein Registry-Pull, keine
# Anmeldung irgendwo. Das Image wird lokal aus dem Dockerfile gebaut.
#
# Jede Frage mit einem Wert in eckigen Klammern laesst sich mit Enter
# uebernehmen; freiwillige Angaben lassen sich leer lassen.

set -euo pipefail

cd "$(dirname "$0")"

info()  { printf '\n\033[1m%s\033[0m\n' "$1"; }
ok()    { printf '\033[32m✓\033[0m %s\n' "$1"; }
warn()  { printf '\033[33m!\033[0m %s\n' "$1"; }
fail()  { printf '\n\033[31mAborted:\033[0m %s\n\n' "$1" >&2; exit 1; }

upstream_url="https://github.com/s3gv/immobase"

# --- Hilfen ------------------------------------------------------------------

# Eine Frage, optional mit Vorgabe. Enter uebernimmt die Vorgabe — oder
# laesst das Feld leer, wenn es keine gibt.
ask() {
    local prompt="$1" default="${2:-}" answer
    if [ -n "$default" ]; then
        read -r -p "$prompt [$default]: " answer || fail "Input ended before all questions were answered."
        printf '%s' "${answer:-$default}"
    else
        read -r -p "$prompt: " answer || fail "Input ended before all questions were answered."
        printf '%s' "$answer"
    fi
}

# Eine Frage, bis eine brauchbare Antwort kommt. Der Pruefer gibt eine
# Meldung aus und endet mit 1, wenn die Antwort nicht taugt.
ask_until() {
    local prompt="$1" default="$2" check="$3" answer
    while true; do
        # "|| exit": die Befehlsersetzung erbt set -e nicht, und ohne das
        # fragte die Schleife nach dem Ende der Eingabe ewig weiter.
        answer=$(ask "$prompt" "$default") || exit 1
        if message=$("$check" "$answer"); then
            printf '%s' "$answer"
            return
        fi
        warn "$message" >&2
    done
}

# Ja oder nein, mit Vorgabe.
ask_yes() {
    local answer
    answer=$(ask "$1 (yes/no)" "$2") || exit 1
    case "$answer" in
        j|ja|J|Ja|y|yes) return 0 ;;
        *) return 1 ;;
    esac
}

# Ein Passwort, unsichtbar und zweimal — ein Tippfehler in einer Eingabe, die
# man nicht sieht, faellt sonst erst beim ersten Anmelden auf.
ask_password() {
    local first second
    while true; do
        printf 'Password (at least 12 characters, input stays hidden): ' >&2
        read -rs first || fail "Input ended before all questions were answered."
        printf '\n' >&2

        if [ ${#first} -lt 12 ]; then
            warn "Too short — at least 12 characters. A whole sentence is easier to remember than special characters." >&2
            continue
        fi

        printf 'Repeat password: ' >&2
        read -rs second || fail "Input ended before all questions were answered."
        printf '\n' >&2

        if [ "$first" = "$second" ]; then
            printf '%s' "$first"
            return
        fi

        warn "The two entries do not match." >&2
    done
}

# git remote liefert je nach Klonweg SSH oder https, mit oder ohne .git.
# Die Adresse steht spaeter auf der Anmeldeseite zum Anklicken — sie muss
# aufrufbar sein und darf keine Zugangsdaten tragen: ein Remote wie
# https://oauth2:TOKEN@example.org/org/repo.git gaebe sonst jedem Besucher
# das Token. Der Teil vor dem @ fliegt raus.
normalise_git_url() {
    printf '%s' "$1" \
        | sed -e 's#^[A-Za-z0-9._-]*@\([^:/]*\):#https://\1/#' \
              -e 's#^ssh://#https://#' \
              -e 's#^\(https\{0,1\}://\)[^/@]*@#\1#' \
              -e 's#\.git$##'
}

# Prozentkodierung fuer Benutzername und Passwort des Mailservers: beide
# landen in smtp://benutzer:passwort@host, und ein "@" im Benutzernamen
# zerlegte die Adresse an der falschen Stelle. Ohne jq — der Installer soll
# auf einem frischen Server laufen.
urlencode() {
    local string="$1" index char output=''

    for (( index = 0; index < ${#string}; index++ )); do
        char="${string:index:1}"
        case "$char" in
            [a-zA-Z0-9.~_-]) output+="$char" ;;
            *) output+=$(printf '%%%02X' "'$char") ;;
        esac
    done

    printf '%s' "$output"
}

# Ein Wert aus einer bestehenden .env.local — oder die Vorgabe.
existing() {
    local value=''
    [ -f .env.local ] && value=$(grep -E "^$1=" .env.local | tail -n1 | cut -d= -f2- || true)
    printf '%s' "${value:-${2:-}}"
}

# --- Pruefer fuer ask_until ----------------------------------------------------

valid_url() {
    case "$1" in
        http://?*|https://?*)
            case "$1" in
                *@*) echo "The address must not contain credentials ('@')."; return 1 ;;
            esac
            ;;
        *) echo "Please start with http:// or https://."; return 1 ;;
    esac
}

valid_http_url() {
    case "$1" in
        http://?*) valid_url "$1" ;;
        *) echo "Without encryption the address starts with http:// — for HTTPS choose 2 or 3."; return 1 ;;
    esac
}

valid_port() {
    case "$1" in
        ''|*[!0-9]*) echo "The port is a number, for example 8080."; return 1 ;;
    esac
    [ "$1" -ge 1 ] && [ "$1" -le 65535 ] || { echo "A port is between 1 and 65535."; return 1; }
}

valid_access() {
    case "$1" in
        1|2|3) ;;
        *) echo "Please enter 1, 2 or 3."; return 1 ;;
    esac
}

# Ein Hostname, wie er in einem Zertifikat steht: keine Adresse mit Schema,
# kein localhost und keine IP — dafuer stellt Let's Encrypt nichts aus.
valid_domain() {
    case "$1" in
        ''|*://*|*/*|*:*|*' '*) echo "Just the name, without https:// and without a path — for example immobase.example.org."; return 1 ;;
        localhost|*.localhost|*.local) echo "Let's Encrypt does not issue certificates for $1. Choose 1 for that."; return 1 ;;
    esac
    printf '%s' "$1" | grep -Eq '^[0-9.]+$' && { echo "Let's Encrypt does not issue certificates for IP addresses."; return 1; }
    printf '%s' "$1" | grep -Eq '^[A-Za-z0-9]([A-Za-z0-9-]*[A-Za-z0-9])?(\.[A-Za-z0-9]([A-Za-z0-9-]*[A-Za-z0-9])?)+$' && return 0
    echo "That does not look like a domain."
    return 1
}

# Zeigt die Domain ueberhaupt irgendwohin? Ob auf diesen Rechner, laesst sich
# ohne einen Dienst im Internet nicht pruefen — und den fragt der Installer
# nicht. Loest sie gar nicht auf, scheitert das Zertifikat sicher.
check_dns() {
    local resolved=''

    if command -v getent >/dev/null 2>&1; then
        resolved=$(getent hosts "$1" | head -n1 || true)
    elif command -v host >/dev/null 2>&1; then
        resolved=$(host "$1" 2>/dev/null | grep -i 'has address' | head -n1 || true)
    else
        return 0
    fi

    [ -n "$resolved" ] && { ok "$1 is in DNS"; return 0; }

    warn "$1 cannot be found in DNS. Without a record pointing to this machine, ImmoBase cannot get a certificate."
    ask_yes "Continue anyway?" "no" || fail "Please create the DNS record for $1 first and run install.sh again."
}

valid_locale() {
    case "$1" in
        de|en) ;;
        *) echo "Please enter 'de' or 'en'."; return 1 ;;
    esac
}

valid_region() {
    [ "$1" = "auto" ] && return 0
    printf '%s' "$regions" | tr ' ' '\n' | grep -qx "$1" && return 0
    echo "There is no such background. Possible: auto $regions"
    return 1
}

# Gegen die Zeitzonendatenbank des Rechners. Ein Tippfehler faellt sonst
# erst auf, wenn PHP stillschweigend in UTC rechnet.
valid_timezone() {
    case "$1" in
        ''|*..*|/*) echo "Please enter a time zone such as Europe/Berlin."; return 1 ;;
    esac
    # Ohne Zeitzonendatenbank auf dem Rechner laesst sich nur die Form pruefen.
    [ -d /usr/share/zoneinfo ] || return 0
    [ -f "/usr/share/zoneinfo/$1" ] && return 0
    echo "Unknown time zone. Examples: Europe/Berlin, Europe/Vienna, Europe/Zurich."
    return 1
}

valid_email() {
    case "$1" in
        ?*@?*.?*) ;;
        *) echo "That does not look like an email address."; return 1 ;;
    esac
}

valid_optional_email() {
    [ -z "$1" ] && return 0
    valid_email "$1"
}

info "ImmoBase — Installation"

# --- Voraussetzungen -----------------------------------------------------------

command -v docker >/dev/null 2>&1 \
    || fail "Docker is not installed. Instructions: https://docs.docker.com/get-docker/"

docker compose version >/dev/null 2>&1 \
    || fail "Docker Compose is missing. It is part of current Docker versions — please update Docker."

docker info >/dev/null 2>&1 \
    || fail "Docker is not running. Start Docker Desktop or the Docker service and try again."

command -v openssl >/dev/null 2>&1 \
    || fail "openssl is missing; it is needed to generate the security keys."

ok "Docker is ready"

# Welche Compose-Dateien gelten, steht als COMPOSE_FILE in .env.local — so
# bleibt die Entwicklungsdatei compose.override.yaml draussen, sonst griffen
# Dev-Ports und der Bind-Mount des Quellcodes.
compose() {
    docker compose --env-file .env.local "$@"
}

# Docker Compose benennt Volumes nach dem Verzeichnis, kleingeschrieben und
# ohne Sonderzeichen.
project=$(basename "$PWD" | tr '[:upper:]' '[:lower:]' | tr -cd 'a-z0-9_-')
data_volume="${project}_database_data"

# PostgreSQL setzt das Passwort nur beim allerersten Start eines leeren
# Datenverzeichnisses. Gibt es die Datenbank schon, aber nicht mehr die
# Zugangsdaten dazu, kaeme ImmoBase mit einem neuen Passwort nicht an seine
# eigenen Daten.
if docker volume inspect "$data_volume" >/dev/null 2>&1 && [ ! -f .env.local ]; then
    printf '\n\033[33mA database already exists\033[0m (Docker volume "%s"),\n' "$data_volume"
    printf 'but there is no .env.local with its credentials.\n\n'
    printf 'Two ways forward:\n'
    printf '  1. Restore the old .env.local if you still have it.\n'
    printf '  2. Discard the database and start over:\n'
    printf '     docker volume rm %s\n\n' "$data_volume"
    fail "Please choose one of the two and run install.sh again."
fi

reconfigure=false

if [ -f .env.local ]; then
    printf '\n.env.local already exists — ImmoBase is already installed.\n'
    printf 'Keys, database password and accounts are kept; only what can be\n'
    printf 'changed is asked again. Current values are shown in square brackets\n'
    printf 'and kept when you press Enter.\n\n'
    ask_yes "Rewrite the configuration?" "no" || fail "Nothing changed."
    reconfigure=true
fi

# --- Erreichbarkeit ------------------------------------------------------------

info "Access"
printf 'How will ImmoBase be reached in the browser?\n\n'
printf '  1  On this machine or your own network, without encryption (http)\n'
printf '  2  Through your own domain with HTTPS — ImmoBase gets the certificate\n'
printf '     from Let'"'"'s Encrypt by itself. The domain must point to this machine,\n'
printf '     and ports 80 and 443 must be reachable from the internet.\n'
printf '  3  Through an existing reverse proxy that handles HTTPS\n\n'

access=$(ask_until "Choice" "$(existing IMMOBASE_ACCESS 1)" valid_access)

http_bind=""
http_port="8080"
trusted_proxies=""
domain=""
compose_files="compose.yaml"

case "$access" in
    1)
        public_url=$(ask_until "Address in the browser, for example http://localhost:8080" "$(existing DEFAULT_URI http://localhost:8080 | sed 's#^https://.*#http://localhost:8080#')" valid_http_url)

        # Adresse und Port gehoeren zusammen: ImmoBase lauscht auf dem Port,
        # und dieselbe Adresse steht in Einladungs- und Passwortlinks. Steht
        # der Port in der Adresse, gilt er; sonst wird gefragt, und die
        # Adresse bekommt ihn angehaengt.
        url_host=$(printf '%s' "$public_url" | sed -E 's#^http://([^/:]+).*#\1#')
        url_port=$(printf '%s' "$public_url" | sed -n -E 's#^http://[^/:]+:([0-9]+).*#\1#p')

        if [ -n "$url_port" ]; then
            valid_port "$url_port" >/dev/null || fail "The port in $public_url is invalid."
            http_port="$url_port"
        else
            http_port=$(ask_until "Port on this machine" "$(existing HTTP_PORT 8080)" valid_port)
        fi

        if [ "$http_port" = "80" ]; then
            public_url="http://${url_host}"
        else
            public_url="http://${url_host}:${http_port}"
        fi

        # Auf dem eigenen Rechner verlaesst nichts das Geraet. Von einem
        # anderen aus gehen Passwort und Sitzung lesbar durchs Netz.
        case "$url_host" in
            localhost | 127.0.0.1) ;;
            *) warn "Without HTTPS, passwords and sessions travel over the network unencrypted. For access from other machines, choose 2 or 3." ;;
        esac
        ;;
    2)
        domain=$(ask_until "Domain, for example immobase.example.org" "$(existing IMMOBASE_DOMAIN)" valid_domain)
        public_url="https://${domain}"
        compose_files="compose.yaml:compose.letsencrypt.yaml"
        # Port 80 fuer die Pruefung durch Let's Encrypt und die Umleitung auf
        # https, von ueberall erreichbar; 443 kommt aus compose.letsencrypt.yaml.
        http_port="80"
        check_dns "$domain"
        ;;
    3)
        public_url=$(ask_until "Address in the browser, for example https://immobase.example.org" "$(existing DEFAULT_URI)" valid_url)
        public_url=${public_url%/}
        http_port=$(ask_until "Port the proxy forwards to" "$(existing HTTP_PORT 8080)" valid_port)

        if ask_yes "Does the proxy run on this machine?" "yes"; then
            # Dann erreicht niemand sonst den Port, und die Kopfzeilen des
            # Proxys sind vertrauenswuerdig.
            http_bind="127.0.0.1"
            trusted_proxies="PRIVATE_SUBNETS"
        else
            trusted_proxies=$(ask "IP address of the proxy (empty = trust nobody)")

            # Ohne den Proxy zu kennen, sieht ImmoBase nur dessen http und
            # dessen Adresse: Cookies ohne Secure, kein HSTS, und die Bremse
            # gegen Durchprobieren zaehlt alle Besucher als einen.
            [ -n "$trusted_proxies" ] || warn "Without the proxy address ImmoBase cannot detect HTTPS: session cookies lack the Secure flag, and sign-in throttling counts all visitors as one."
        fi
        ;;
esac

# --- Oberflaeche -------------------------------------------------------------------

info "Interface"

locale=$(ask_until "Interface language (de/en)" "$(existing APP_LOCALE de)" valid_locale)

printf '\nTime zone for times and deadlines, for example Europe/Berlin or Europe/Vienna.\n'
timezone=$(ask_until "Time zone" "$(existing IMMOBASE_TIMEZONE Europe/Berlin)" valid_timezone)

regions=$(ls assets/images/regions/*.svg 2>/dev/null | xargs -n1 basename 2>/dev/null | sed 's/\.svg$//' | sort | tr '\n' ' ')
printf '\nBackground image: a German state or auto. Possible: auto %s\n' "$regions"
region=$(ask_until "Background image" "$(existing IMMOBASE_REGION auto)" valid_region)

# AGPL §13: die Adresse muss auf den Quellcode DIESER Installation zeigen.
# Vorgeschlagen wird die Herkunft des Arbeitsverzeichnisses — wer einen Fork
# geklont hat, bekommt seine eigene Adresse. Ist sie keine Webadresse (etwa
# ein lokaler Pfad), bleibt das Original als Vorschlag.
source_default=$(normalise_git_url "$(git remote get-url origin 2>/dev/null || true)")
valid_url "$source_default" >/dev/null 2>&1 || source_default="$upstream_url"

printf '\nThe AGPL requires that users can get the source code of the running version.\n'
printf 'If you have modified ImmoBase, enter the address of your own repository.\n'
source_url=$(ask_until "Source code address" "$(existing IMMOBASE_SOURCE_URL "$source_default")" valid_url)

# --- Erstes Konto --------------------------------------------------------------

admin_email=""

if [ "$reconfigure" = false ]; then
    info "First account"
    printf 'This account has all permissions and creates the others.\n\n'

    admin_email=$(ask_until "Email address" "" valid_email)
    admin_given_name=$(ask "Given name (optional)")
    admin_family_name=$(ask "Family name (optional)")
    admin_password=$(ask_password)
fi

# Let's Encrypt schreibt an diese Adresse, wenn ein Zertifikat nicht erneuert
# werden kann — rechtzeitig, bevor die Seite im Browser eine Warnung zeigt.
acme_email=""
if [ "$access" = "2" ]; then
    acme_email=$(ask_until "Email for notices from Let's Encrypt" "$(existing ACME_EMAIL "$admin_email")" valid_email)
fi

# --- Mailserver ----------------------------------------------------------------
#
# Freiwillig. Ohne Mailserver zeigt die Oberflaeche Einladungslinks zum
# Kopieren an. Wer gerade keine SMTP-Daten zur Hand hat, soll trotzdem
# installieren koennen — nachtragen laesst es sich in .env.local.

info "Mail server (optional)"
printf 'ImmoBase sends invitations and password reset links.\n'
printf 'Leave empty if there is none — invitation links are then shown in the interface.\n\n'

mailer_dsn='null://null'
mailer_from=$(existing MAILER_FROM "$admin_email")
smtp_host=""

if [ "$(existing MAILER_DSN null://null)" != 'null://null' ] && ask_yes "Keep the current mail settings?" "yes"; then
    mailer_dsn=$(existing MAILER_DSN)
    smtp_host="as before"
else
    smtp_host=$(ask "SMTP server (empty = no mail)")
fi

if [ "$smtp_host" = "as before" ]; then
    ok "Mail settings stay as they were"
elif [ -n "$smtp_host" ]; then
    smtp_port=$(ask_until "Port" "587" valid_port)
    smtp_user=$(ask "User name (empty = no authentication)")
    smtp_password=""

    if [ -n "$smtp_user" ]; then
        printf 'SMTP password (input stays hidden): '
        read -rs smtp_password || fail "Input ended before all questions were answered."
        printf '\n'
        mailer_dsn="smtp://$(urlencode "$smtp_user"):$(urlencode "$smtp_password")@${smtp_host}:${smtp_port}"
    else
        mailer_dsn="smtp://${smtp_host}:${smtp_port}"
    fi

    mailer_from=$(ask_until "Sender address" "$mailer_from" valid_email)
else
    ok "No mail — invitation links are shown in the interface"
fi

# --- Zusammenfassung ---------------------------------------------------------------

info "Summary"
printf '  Address:        %s (ImmoBase answers only under this name)\n' "$public_url"
case "$access" in
    1) printf '  Access:         http, port %s\n' "$http_port" ;;
    2) printf "  Access:         HTTPS with Let's Encrypt (ports 80 and 443), notices to %s\n" "$acme_email" ;;
    3) printf '  Access:         reverse proxy on port %s (%s)\n' "$http_port" "$([ "$http_bind" = "127.0.0.1" ] && echo 'this machine only' || echo 'all networks')" ;;
esac
printf '  Language:       %s\n' "$locale"
printf '  Time zone:      %s\n' "$timezone"
printf '  Background:     %s\n' "$region"
printf '  Source code:    %s\n' "$source_url"
[ -z "$admin_email" ] || printf '  First account:  %s\n' "$admin_email"
printf '  Mail:           %s\n' "$([ "$mailer_dsn" = 'null://null' ] && echo 'none' || echo "$smtp_host")"
printf '\n'
ask_yes "Start the installation?" "yes" || fail "Nothing changed."

# --- Konfiguration schreiben ---------------------------------------------------

info "Configuration"

# Bestehende Schluessel bleiben. Ein neues Datenbankpasswort wuerde PostgreSQL
# ignorieren, ein neuer Anhangschluessel machte gespeicherte Anhaenge
# unlesbar.
app_secret=$(existing APP_SECRET "$(openssl rand -hex 32)")
postgres_password=$(existing POSTGRES_PASSWORD "$(openssl rand -hex 24)")

# Der Schluessel fuer die Anhaenge im Mieterportal: 32 Byte, base64. Ein
# eigener und nicht APP_SECRET — wer das wechselt, soll nicht nebenbei alle
# Anhaenge unlesbar machen.
file_key=$(existing IMMOBASE_FILE_KEY "$(openssl rand -base64 32)")

commit=$(git rev-parse --short HEAD 2>/dev/null || echo unknown)

umask 077
cat > .env.local <<ENVEOF
# Generated by install.sh on $(date -u +%Y-%m-%dT%H:%M:%SZ).
# This file contains credentials and does not belong in version control.
# After a change: docker compose --env-file .env.local up -d

# Which Compose files apply. The development file compose.override.yaml is
# deliberately not included.
COMPOSE_FILE=${compose_files}

APP_ENV=prod
APP_SECRET=${app_secret}
APP_LOCALE=${locale}
IMMOBASE_TIMEZONE=${timezone}
APP_COMMIT=${commit}

# Address of this installation as typed into the browser. It is used in links
# created outside a request, such as notifications.
DEFAULT_URI=${public_url}

# 1 = http, 2 = HTTPS with Let's Encrypt, 3 = behind a reverse proxy
IMMOBASE_ACCESS=${access}
IMMOBASE_DOMAIN=${domain}
ACME_EMAIL=${acme_email}

# Port on this machine and who may reach it. With 127.0.0.1 in front: only a
# reverse proxy on the same machine, otherwise all addresses.
HTTP_PORT=${http_port}
HTTP_PUBLISH=${http_bind:+${http_bind}:}${http_port}

# Who is trusted for X-Forwarded-For and -Proto. Empty means: nobody.
TRUSTED_PROXIES=${trusted_proxies}

IMMOBASE_REGION=${region}

# AGPL §13: source code address of this installation. If ImmoBase is modified,
# it must point to the modified version.
IMMOBASE_SOURCE_URL=${source_url}

POSTGRES_DB=immobase
POSTGRES_USER=immobase
POSTGRES_PASSWORD=${postgres_password}

# Mail. null://null means nothing is sent, and the interface shows invitation
# links for copying instead.
MAILER_DSN=${mailer_dsn}
MAILER_FROM=${mailer_from}

# Encrypts tenant portal attachments in the database. If it is lost, stored
# attachments become unreadable — they only live for days anyway.
IMMOBASE_FILE_KEY=${file_key}
ENVEOF
umask 022

ok "Credentials stored in .env.local (readable only by you)"

# --- Bauen und starten -----------------------------------------------------

info "Building the image — the first time takes a few minutes"

# Mit --pull und dem frischen Datenbank-Image: ein Image, das einmal auf der
# Platte liegt, bliebe sonst fuer immer auf dem Stand seines ersten Downloads —
# ohne die Sicherheitsupdates von PHP, Caddy und PostgreSQL, die seitdem
# erschienen sind. Innerhalb von PostgreSQL 16 bleibt das Datenformat gleich.
compose build --pull \
    || fail "The build failed. The output above shows why."

compose pull database \
    || fail "The database image could not be pulled. Is there an internet connection?"

info "Starting services"

compose up -d \
    || fail "The services could not be started. Is a port already in use ($([ "$access" = "2" ] && echo '80 or 443' || echo "$http_port"))? Details: docker compose --env-file .env.local logs"

# Beim Start migriert der Container die Datenbank und startet dann den
# Webserver. Bereit ist er, wenn sein Gesundheitscheck das sagt.
printf 'Waiting for ImmoBase to be ready'
app_container=$(compose ps -q app)
status=""
for _ in $(seq 1 90); do
    status=$(docker inspect --format '{{.State.Health.Status}}' "$app_container" 2>/dev/null || echo starting)
    [ "$status" = "healthy" ] && break
    printf '.'
    sleep 2
done
printf '\n'

[ "$status" = "healthy" ] \
    || fail "ImmoBase did not come up. Details: docker compose --env-file .env.local logs app"

ok "ImmoBase is running"

# --- Erstes Konto anlegen ------------------------------------------------------
#
# Das Passwort geht ueber die Standardeingabe und nie als Argument: das stuende
# in der Prozessliste. Lehnt ImmoBase es ab — zu leicht zu erraten, der eigene
# Name darin, aus einem bekannten Datenleck —, wird neu gefragt.

if [ "$reconfigure" = false ]; then
    info "Creating the first account"

    while true; do
        set +e
        printf '%s\n' "$admin_password" \
            | compose exec -T app php bin/console immobase:user:create --no-interaction \
                "$admin_email" "$admin_given_name" "$admin_family_name" --admin
        created=$?
        set -e

        [ $created -eq 0 ] && break

        # 2 heisst: das Passwort erfuellt die Regeln nicht. Alles andere ist kein
        # Fall fuer eine neue Eingabe.
        [ $created -eq 2 ] || fail "The account could not be created. The message above shows why."

        printf 'Please choose a different password.\n'
        admin_password=$(ask_password)
    done

    ok "Account $admin_email created"
fi

# Ein Mailserver, der erst bei der ersten echten Einladung auffaellt, kostet
# mehr Zeit als eine Probe jetzt.
if [ "$mailer_dsn" != "null://null" ]; then
    info "Test mail"

    if compose exec -T app php bin/console immobase:mail:test "${admin_email:-$mailer_from}"; then
        ok "Test email sent to ${admin_email:-$mailer_from}"
    else
        warn "The test mail failed. ImmoBase runs anyway — check MAILER_DSN in .env.local."
    fi
fi

# --- Zertifikat ---------------------------------------------------------------
#
# Caddy holt das Zertifikat im Hintergrund, sobald der Container laeuft. Das
# dauert meist Sekunden. Ein gescheiterter Versuch heisst noch nichts: Caddy
# faellt auf eine zweite Zertifizierungsstelle zurueck. Klappt es gar nicht,
# laeuft ImmoBase trotzdem, und Caddy versucht es weiter — der haeufigste Grund ist ein DNS-Eintrag oder eine
# Firewall, und beides laesst sich beheben, ohne neu zu installieren.

certificate="not needed"

if [ "$access" = "2" ]; then
    info "Certificate from Let's Encrypt"
    printf 'Waiting for the certificate for %s' "$domain"
    certificate="pending"

    for _ in $(seq 1 45); do
        # Im Zertifikatsspeicher und nicht im Protokoll nachsehen: ein
        # Zertifikat aus einem frueheren Lauf laedt Caddy still, ohne es
        # erneut zu melden.
        if compose exec -T app sh -c "ls /data/caddy/certificates/*/'$domain'/'$domain'.crt" >/dev/null 2>&1; then
            certificate="issued"
            break
        fi

        printf '.'
        sleep 2
    done
    printf '\n'

    case "$certificate" in
        issued) ok "Certificate for $domain is in place" ;;
        *)
            warn "The certificate for $domain is not there yet."
            printf '  ImmoBase is running and keeps trying. Usually it is one of two things:\n'
            printf '  - The DNS record for %s does not point to this machine.\n' "$domain"
            printf '  - Ports 80 and 443 are not reachable from the internet (firewall, router).\n'
            printf '  To check: docker compose --env-file .env.local logs app | grep -i certificate\n'
            ;;
    esac
fi

# --- Fertig ----------------------------------------------------------------

printf '\n\033[32m────────────────────────────────────────────\033[0m\n'
if [ "$certificate" = "pending" ]; then
    printf '\033[1mImmoBase is running — HTTPS follows once the certificate is in place.\033[0m\n\n'
else
    printf '\033[1mImmoBase is ready.\033[0m\n\n'
fi
printf '  Address:  %s\n' "$public_url"
[ -z "$admin_email" ] || printf '  Sign in:  %s\n' "$admin_email"
printf '\n'

if [ "$http_bind" = "127.0.0.1" ]; then
    printf 'The reverse proxy must forward %s to http://127.0.0.1:%s.\n\n' "$public_url" "$http_port"
fi

printf 'ImmoBase starts by itself after the machine restarts.\n\n'
printf 'Stop:   docker compose --env-file .env.local down\n'
printf 'Start:  docker compose --env-file .env.local up -d\n'
printf '\033[32m────────────────────────────────────────────\033[0m\n\n'
