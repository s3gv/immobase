# SPDX-License-Identifier: AGPL-3.0-or-later
# SPDX-FileCopyrightText: 2026 s3gv

# Die Helfer, die Plugin-Prozesse unter einer eigenen Kennung starten und ihr
# Netz sperren — siehe docker/plugin-runner/. In einer eigenen Stufe gebaut,
# damit der Compiler nicht im Image landet.
FROM dunglas/frankenphp:php8.4 AS plugin-runner

RUN apt-get update \
    && DEBIAN_FRONTEND=noninteractive apt-get install -y --no-install-recommends gcc libc6-dev \
    && rm -rf /var/lib/apt/lists/*
COPY docker/plugin-runner/run-as-plugin.c docker/plugin-runner/plugin-firewall.c /src/
RUN gcc -O2 -Wall -Wextra -Werror -o /run-as-plugin /src/run-as-plugin.c \
    && gcc -O2 -Wall -Wextra -Werror -o /plugin-firewall /src/plugin-firewall.c

FROM dunglas/frankenphp:php8.4 AS base

# gd und zlib braucht FPDF fuer Bilder im Briefkopf. Was composer.json an
# Erweiterungen verlangt, prueft der Build unten — ein fehlendes Modul faellt
# dort auf und nicht beim ersten Schreiben mit Logo.
# opcache bringt das Image selbst mit. unzip, weil Composer Pakete sonst ueber
# die PHP-Erweiterung entpackt und dabei jedes Mal davor warnt — auch wenn ein
# Plugin im Betrieb seine Abhaengigkeiten nachinstalliert. iptables fuer die
# Netzsperre der Plugins.
RUN install-php-extensions \
    gd \
    intl \
    pdo_pgsql \
    zip \
    && apt-get update \
    && DEBIAN_FRONTEND=noninteractive apt-get install -y --no-install-recommends unzip iptables \
    && rm -rf /var/lib/apt/lists/*

# Was ImmoBase an PHP-Vorgaben ändert. In der Basis und nicht je Ziel: die
# Grenzen für Uploads gelten im Betrieb wie in der Entwicklung, und eine
# Entwicklungsumgebung, die mehr annimmt als der Betrieb, verschiebt den
# Fehler nur nach hinten.
COPY docker/php/immobase.ini $PHP_INI_DIR/conf.d/immobase.ini

# Die Zeitzone der Installation. Ohne sie rechnet PHP in UTC, und jede Uhrzeit
# in der Oberflaeche stuende zwei Stunden daneben. In der PHP-Konfiguration
# und nicht in der Anwendung: die Plugin-Prozesse sind ebenfalls PHP und
# sollen dieselbe Uhr haben.
ARG TIMEZONE=Europe/Berlin
RUN printf 'date.timezone = %s\n' "$TIMEZONE" > "$PHP_INI_DIR/conf.d/timezone.ini"

# Eine eigene Caddyfile statt der aus dem Image: dieselben Bausteine, aber
# ohne auskommentierte Beispiele, ohne Import eines leeren Verzeichnisses und
# ohne HTTP/2 und HTTP/3 auf dem unverschluesselten Port — beides meldete
# Caddy bei jedem Start als Warnung.
COPY docker/frankenphp/Caddyfile /etc/frankenphp/Caddyfile
COPY docker/frankenphp/start.sh /usr/local/bin/immobase-start
HEALTHCHECK --interval=30s --timeout=3s CMD curl -fsS -o /dev/null http://127.0.0.1:2019/healthz || exit 1

WORKDIR /app

COPY --from=composer/composer:2-bin /composer /usr/bin/composer

# Nicht als root. Die Anwendung laeuft als eigener Benutzer; die Ports 80 und
# 443 darf FrankenPHP trotzdem oeffnen, dafuer genuegt diese eine Faehigkeit
# statt aller. Wer den Webserver uebernimmt, ist damit nicht root im
# Container — und die Plugin-Prozesse, die er startet, auch nicht.
#
# UID und GID lassen sich beim Bau setzen: in der Entwicklung sollen Dateien,
# die der Container in den eingebundenen Quellcode schreibt, dem Menschen am
# Rechner gehoeren.
ARG UID=1000
ARG GID=1000
RUN (getent group ${GID} >/dev/null || groupadd --gid ${GID} immobase) \
    && useradd --uid ${UID} --gid ${GID} --home-dir /home/immobase --create-home --shell /usr/sbin/nologin immobase \
    && setcap CAP_NET_BIND_SERVICE=+eip /usr/local/bin/frankenphp \
    && mkdir -p /data/caddy /config/caddy \
    && chown -R immobase:${GID} /data /config

# Jedes Plugin laeuft unter einer eigenen Kennung, nicht als `immobase`: ein
# Plugin soll weder die Umgebung des Cores noch das Token eines anderen Plugins
# lesen koennen. Der Helfer wechselt nur in den Bereich ab 101024; ausfuehren
# darf ihn nur die Gruppe des Cores. Die Kennungen brauchen keinen Eintrag in
# /etc/passwd.
#
# Und jedes nur hinter einer Netzsperre: die Schnittstelle des Cores, die
# Datenbank, mit Zustimmung das Internet — sonst nichts. plugin-firewall haelt
# dafuer CAP_NET_ADMIN; den Container muss compose.yaml damit starten. Ohne
# Sperre verweigert run-as-plugin den Start.
COPY --from=plugin-runner /run-as-plugin /plugin-firewall /usr/local/libexec/immobase/
COPY docker/plugin-runner/plugin-firewall.sh /usr/local/libexec/immobase/plugin-firewall.sh
RUN chown root:${GID} /usr/local/libexec/immobase/run-as-plugin /usr/local/libexec/immobase/plugin-firewall \
    && chmod 0750 /usr/local/libexec/immobase/run-as-plugin /usr/local/libexec/immobase/plugin-firewall \
    && chmod 0644 /usr/local/libexec/immobase/plugin-firewall.sh \
    && setcap CAP_SETUID,CAP_SETGID=+ep /usr/local/libexec/immobase/run-as-plugin \
    && setcap CAP_NET_ADMIN=+ep /usr/local/libexec/immobase/plugin-firewall
ENV IMMOBASE_PLUGIN_RUNNER=/usr/local/libexec/immobase/run-as-plugin \
    IMMOBASE_PLUGIN_FIREWALL=/usr/local/libexec/immobase/plugin-firewall

FROM base AS app

# APP_ENV steht bewusst VOR den Composer-Skripten. Andernfalls wärmt
# post-install-cmd den dev-Cache, der MakerBundle braucht — das mit --no-dev
# gar nicht installiert ist. Genau daran scheiterte der Build früher, und ein
# angehängtes "|| true" hätte auch echte Fehler beim Autoload-Dump verschluckt.
ENV APP_ENV=prod

COPY composer.json composer.lock symfony.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --no-interaction --prefer-dist \
    && composer check-platform-reqs --no-dev

COPY . .
RUN composer dump-autoload --optimize --no-dev --classmap-authoritative

# Jedes Plugin bringt seine Abhängigkeiten selbst mit — in seinem eigenen
# vendor/, mit seinem eigenen Autoloader. Sie werden hier installiert und
# nicht in den Core gemischt: ein Plugin, das mit dem Core einen Autoloader
# teilte, wäre mit ihm ein gemeinsames Werk.
RUN for plugin in plugins/*/; do \
        if [ -f "$plugin/composer.json" ]; then \
            composer install --working-dir="$plugin" --no-dev --no-interaction --prefer-dist --optimize-autoloader --no-scripts --no-plugins; \
        fi; \
    done
RUN composer run-script post-install-cmd --no-interaction

# Stylesheet bauen und Assets versionieren. Beides gehört in den Build, nicht
# in den Containerstart: sonst wäre der erste Aufruf nach jedem Neustart
# langsam, und ein Fehler fiele erst im Betrieb auf.
RUN php bin/console tailwind:build --minify \
    && php bin/console asset-map:compile

# Der Code gehoert root und bleibt fuer die Anwendung schreibgeschuetzt.
# Schreiben darf sie nur, wo sie muss: in var/ (Cache, Sitzungen, Zustand der
# Plugin-Prozesse) und in plugins/, wo ein Plugin seine Abhaengigkeiten
# nachinstalliert.
#
# var/ ist nur fuer die Anwendung selbst lesbar. Die Plugin-Prozesse laufen
# unter anderen Kennungen und brauchen dort nichts — koennten sie hineinsehen,
# laesen sie in var/sessions die Namen der Sitzungsdateien, und der Name ist
# die Sitzungskennung aus dem Cookie.
RUN mkdir -p var/plugins var/sessions \
    && chown -R immobase var plugins \
    && chmod 0750 var \
    && chmod 0700 var/sessions var/plugins

# Die Vorgaben fuer den Betrieb: keine Fehlermeldungen in Antworten, keine
# Pfade und Versionen im Browser. Symfony faengt seine eigenen Fehler ab —
# die Plugin-Prozesse sind aber auch PHP, und dort gilt sonst die
# Entwicklungsvorgabe des Images.
RUN cp "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"

USER immobase

EXPOSE 80

# Auch ohne compose.yaml ueber das Startskript: es bringt die Datenbank auf
# den Stand und startet nicht mit den oeffentlichen Entwicklungswerten.
CMD ["immobase-start"]

# Ziel für die lokale Entwicklung: mit Dev-Abhängigkeiten, damit APP_ENV=dev
# funktioniert (Profiler, MakerBundle). compose.override.yaml bindet
# zusätzlich den Quellcode ein, sodass Änderungen ohne Neubau wirken.
FROM base AS dev

ENV APP_ENV=dev
USER immobase
EXPOSE 80
