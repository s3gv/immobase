<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->
<!-- SPDX-FileCopyrightText: 2026 s3gv -->

# ADR 0005 — Alle Prüfungen in bin/check, vorerst ohne CI

- **Status:** Akzeptiert
- **Datum:** 2026-09-09

## Kontext

Solange das Repository privat ist, verbrauchen GitHub Actions Guthaben. Für
öffentliche Repositories sind sie kostenlos.

## Entscheidung

Alle Prüfungen liegen in einem einzigen Skript, `bin/check`. Der Pre-Push-Hook
ruft ausschließlich dieses Skript auf. Ein GitHub-Workflow kommt zum Abschluss
der Entwicklung dazu und ruft dasselbe Skript auf.

Warnungen gelten als Fehler, Deprecations eingeschlossen. Ausgenommen bleiben
Deprecations, die Fremdbibliotheken untereinander auslösen — sie sind nicht
behebbar, und ein Fehler, den niemand beheben kann, wird binnen Wochen
umgangen.

## Konsequenzen

- **Für fremde Pull Requests gibt es kein automatisches Qualitätstor**, solange
  kein Workflow existiert. Ein Hook läuft auf dem Rechner des Entwicklers, und
  `--no-verify` umgeht ihn. Bis zum Public-Gang wird von Hand geprüft.
- Der spätere Workflow ist ein Fünfzeiler, weil er nur `bin/check` aufruft.
  Prüfungen werden nie in YAML dupliziert.
- Der Hook muss schnell bleiben. Wird er unangenehm langsam, ist das ein Signal,
  die Testsuite zu straffen — nicht, den Hook zu überspringen.
