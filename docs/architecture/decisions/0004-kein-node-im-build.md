<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->
<!-- SPDX-FileCopyrightText: 2026 s3gv -->

# ADR 0004 — Kein Node im Build

- **Status:** Akzeptiert
- **Datum:** 2026-09-09

## Kontext

ImmoBase soll sich mit einem Befehl auf einem Server installieren lassen, auf
dem nur Docker läuft. Jede zusätzliche Werkzeugkette ist eine zusätzliche Hürde
und eine zusätzliche Fehlerquelle bei der Installation.

## Entscheidung

Kein Node, kein npm, kein Bundler. Tailwind läuft über das Standalone-Binary,
JavaScript wird über Importmap ausgeliefert und besteht aus Vanilla-Modulen.

## Konsequenzen

- Bibliotheken, die es nur über npm gibt, stehen nicht zur Verfügung. Das ist
  die eigentliche Einschränkung und wird bewusst in Kauf genommen.
- Es gibt keinen Build-Schritt für JavaScript; Module werden so ausgeliefert,
  wie sie geschrieben sind.
- Der Docker-Bau bleibt einstufig und braucht kein Node-Basisimage.
