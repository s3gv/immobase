#!/bin/sh
# SPDX-License-Identifier: AGPL-3.0-or-later
# SPDX-FileCopyrightText: 2026 s3gv
#
# Was beim Start des Anwendungscontainers passiert.
#
# Zuerst die Datenbank auf den Stand dieses Codes bringen — bei der
# Installation wie nach einem Update. Scheitert das, endet der Container, und
# Docker startet ihn neu: besser nicht erreichbar als mit halbem Schema.
#
# Daneben der Hintergrundlauf: abgelaufene Anhaenge loeschen, faellige
# Benachrichtigungen verschicken, alte Protokollzeilen wegraeumen, Plugins
# betreiben, Webhooks zustellen. Kein eigener Container dafuer; die Schleife
# startet den Lauf neu, falls er stirbt, und der Webserver merkt davon nichts.

set -e

# Nicht mit den Vorgaben aus der .env des Repositorys in Betrieb gehen. Die
# stehen oeffentlich auf GitHub: mit ihnen liessen sich Anmeldeschluessel
# nachrechnen, und die Datenbank nimmt ein Passwort an, das jeder kennt — auch
# jedes Plugin im selben Container. install.sh schreibt echte Werte nach
# .env.local; wer ohne install.sh startet, muss sie selbst setzen.
if [ "${APP_ENV:-prod}" = "prod" ]; then
    case "${APP_SECRET:-}" in
        "" | dev-only-not-a-secret-*)
            echo "ImmoBase startet nicht: APP_SECRET fehlt oder ist der Entwicklungswert. install.sh erzeugt einen." >&2
            exit 1
            ;;
    esac

    case "${DATABASE_URL:-}" in
        *://*:immobase@*)
            echo "ImmoBase startet nicht: die Datenbank hat das Entwicklungspasswort. install.sh erzeugt eines." >&2
            exit 1
            ;;
    esac
fi

# Was die Anwendung anlegt, liest kein anderer Benutzer im Container — auch
# kein Plugin. Die Sitzungen liegen in einem Volume, das aelter sein kann als
# dieses Image; seine Rechte werden deshalb hier gesetzt und nicht nur beim Bau.
umask 027
mkdir -p var/sessions var/plugins
chmod 0750 var
chmod 0700 var/sessions var/plugins

php bin/console doctrine:migrations:migrate --no-interaction --allow-no-migration

(
    while true; do
        php bin/console immobase:background || true
        sleep 5
    done
) &

exec frankenphp run --config /etc/frankenphp/Caddyfile --adapter caddyfile
