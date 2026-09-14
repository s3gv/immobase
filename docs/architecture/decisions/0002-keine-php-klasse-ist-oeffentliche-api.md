<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->
<!-- SPDX-FileCopyrightText: 2026 s3gv -->

# ADR 0002 — Keine PHP-Klasse ist öffentliche API

- **Status:** Akzeptiert
- **Datum:** 2026-09-09

## Kontext

ImmoBase steht unter AGPLv3 und soll ein Ökosystem kommerzieller Plugins
tragen — auch von Dritten. Ob ein Plugin als eigenständiges Werk gilt oder als
Teil eines gemeinsamen Werks, hängt maßgeblich von der Art der Kopplung ab. Je
loser sie ist, desto eher bleibt das Plugin rechtlich getrennt.

WordPress zeigt das Muster: Core unter GPL, Plugins hängen über eine öffentliche
Schnittstelle daran und sind oft proprietär. Dort laufen sie allerdings im
selben Prozess, was rechtlich seit Jahren umstritten ist.

## Entscheidung

Plugins laufen als **eigene Prozesse** (im Anwendungscontainer, vom Core gestartet). Sie laden niemals Core-Code, teilen
keinen Autoloader und laufen nie im selben Request. Die Verständigung läuft
ausschließlich über eine Netzwerkgrenze: HTTP-API, ausgehende Events, Manifest.

Damit ist ausdrücklich **keine PHP-Klasse des Cores** öffentliche Schnittstelle.

## Konsequenzen

- Kein Hook-System im Stil von WordPress. Ein Plugin kann dem Core kein
  Twig-Template unterschieben und keinen Dienst überschreiben.
- Plugins rendern ihre Oberfläche selbst; der Core verlinkt nur.
- Die Schnittstelle muss wirklich stabil und versioniert sein, nicht nur eine
  interne Konvention — Dritte sollen sich darauf verlassen können.
- Der Preis ist Latenz und Umstand bei jedem Zugriff. Genau deshalb gilt diese
  Strenge nur an dieser einen Grenze und nicht zwischen Core-Modulen.
