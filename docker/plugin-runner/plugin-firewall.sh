#!/bin/sh
# SPDX-License-Identifier: AGPL-3.0-or-later
# SPDX-FileCopyrightText: 2026 s3gv
#
# Die Netzsperre der Plugin-Kennungen. Aufgerufen nur ueber plugin-firewall,
# das die Argumente geprueft hat — siehe dort.
#
#   plugin-firewall.sh <core-port> <db-port> <db-ipv4,...> [internet-kennung ...]
#
# Fuer jede Plugin-Kennung gilt, in dieser Reihenfolge:
#
#   1. Antworten auf Verbindungen, die schon bestehen — der Core ruft das
#      Plugin auf seinem Port an, und das Plugin muss antworten koennen.
#   2. Die Schnittstelle des Cores: 127.0.0.1 auf ihrem eigenen Port, der nur
#      /api/v1 und /plugin-api kennt, nicht die Anwendung.
#   3. Die Datenbank auf ihrem Port. Anmelden kann sich das Plugin dort nur mit
#      seiner eigenen Rolle, und die darf nur ihr eigenes Schema.
#   4. Mit Zustimmung das Internet — aber nie private Netze, Link-Local mit den
#      Metadaten einer Cloud, localhost oder Multicast. Namen loest es ueber den
#      Resolver des Containers auf.
#   5. Sonst nichts. Abgelehnt statt verworfen: ein Plugin haengt nicht in
#      Zeitueberschreitungen, es bekommt sofort „No route to host".
#
# Die Ketten werden bei jedem Aufruf ganz neu geschrieben, in einem Zug je
# Protokoll. Es gibt keinen Zwischenstand, in dem ein Plugin mehr darf.

set -eu

core_port=$1
db_port=$2
db_addresses=$3
shift 3

range=101024-165535

v4() {
    echo '*filter'
    echo ':IMMOBASE_PLUGINS - [0:0]'
    echo ':IMMOBASE_INTERNET - [0:0]'
    echo '-A IMMOBASE_PLUGINS -m conntrack --ctstate ESTABLISHED,RELATED -j ACCEPT'
    echo "-A IMMOBASE_PLUGINS -o lo -p tcp -d 127.0.0.1 --dport $core_port -j ACCEPT"

    for address in $(echo "$db_addresses" | tr ',' ' '); do
        echo "-A IMMOBASE_PLUGINS -p tcp -d $address --dport $db_port -j ACCEPT"
    done

    echo '-A IMMOBASE_PLUGINS -j IMMOBASE_INTERNET'
    echo '-A IMMOBASE_PLUGINS -j REJECT --reject-with icmp-admin-prohibited'

    for uid in "$@"; do
        echo "-A IMMOBASE_INTERNET -m owner --uid-owner $uid -d 127.0.0.11 -j ACCEPT"

        for net in 0.0.0.0/8 10.0.0.0/8 100.64.0.0/10 127.0.0.0/8 169.254.0.0/16 172.16.0.0/12 \
            192.0.0.0/24 192.0.2.0/24 192.88.99.0/24 192.168.0.0/16 198.18.0.0/15 198.51.100.0/24 \
            203.0.113.0/24 224.0.0.0/3; do
            echo "-A IMMOBASE_INTERNET -m owner --uid-owner $uid -d $net -j RETURN"
        done

        echo "-A IMMOBASE_INTERNET -m owner --uid-owner $uid -j ACCEPT"
    done

    echo 'COMMIT'
}

v6() {
    echo '*filter'
    echo ':IMMOBASE_PLUGINS - [0:0]'
    echo ':IMMOBASE_INTERNET - [0:0]'
    echo '-A IMMOBASE_PLUGINS -m conntrack --ctstate ESTABLISHED,RELATED -j ACCEPT'
    echo '-A IMMOBASE_PLUGINS -j IMMOBASE_INTERNET'
    echo '-A IMMOBASE_PLUGINS -j REJECT --reject-with icmp6-adm-prohibited'

    for uid in "$@"; do
        for net in ::/128 ::1/128 ::ffff:0:0/96 64:ff9b::/96 100::/64 2001:db8::/32 fc00::/7 fe80::/10 ff00::/8; do
            echo "-A IMMOBASE_INTERNET -m owner --uid-owner $uid -d $net -j RETURN"
        done

        echo "-A IMMOBASE_INTERNET -m owner --uid-owner $uid -j ACCEPT"
    done

    echo 'COMMIT'
}

v4 "$@" | iptables-restore -w --noflush
v6 "$@" | ip6tables-restore -w --noflush

# Der Sprung aus OUTPUT, ganz vorn und nur einmal. Steht er schon, bleibt er
# stehen: ihn zum Neusetzen zu entfernen, liesse einen Augenblick ohne Sperre.
for tables in iptables ip6tables; do
    "$tables" -w -C OUTPUT -m owner --uid-owner "$range" -j IMMOBASE_PLUGINS 2>/dev/null \
        || "$tables" -w -I OUTPUT 1 -m owner --uid-owner "$range" -j IMMOBASE_PLUGINS
done
