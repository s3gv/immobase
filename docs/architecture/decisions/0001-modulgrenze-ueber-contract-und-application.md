<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->
<!-- SPDX-FileCopyrightText: 2026 s3gv -->

# ADR 0001 — Modulgrenze über Contract und Application

- **Status:** Akzeptiert
- **Datum:** 2026-09-09

## Kontext

Im Referenzsystem galt die Regel „Module reden nur über Application-Services
miteinander" als Prosa in einer Konventionsdatei. Sie hat nicht gehalten: dessen
ADR 0015 dokumentiert, wie nach fünf Sprints Hub-Module, direkte
Repository-Injection zwischen Modulen und modulübergreifend aggregierende
Controller existierten — und legalisiert diese Muster nachträglich, weil ein
Rückbau zu teuer geworden war.

Der Grund für das Scheitern ist nicht Nachlässigkeit, sondern dass die Regel
nirgends durchgesetzt wurde. Eine Regel ohne Durchsetzung ist eine Bitte.

## Entscheidung

Jedes Modul hat vier Verzeichnisse. `Contract` und `Application` sind für andere
Module sichtbar; `Domain`, `Infrastructure` und `UserInterface` sind modulintern.
`Shared` darf von allen benutzt werden und kennt kein Fachmodul.

Es gibt keine Modul-Hierarchie. Würde ein synchroner Aufruf einen Zyklus
zwischen zwei Modulen erzeugen, wird daraus ein Domain-Event.

Deptrac erzwingt das bei jedem Push. Ein Verstoß ist ein Fehler.

## Warum nicht strenger

Der ursprüngliche Entwurf ließ nur `Contract` zu. Das hätte bedeutet, dass jedes
Modul für jeden fremden Lesezugriff eine eigene Methode anbietet — genau das,
was ADR 0015 des Referenzsystems „Wert null, Komplexität hoch" nennt.

Die Strenge im Inneren zahlt auch nicht auf das Lizenzmodell ein: alle
Core-Module stehen unter AGPLv3, wie eng sie gekoppelt sind, ist eine reine
Wartbarkeitsfrage. Die lizenzrechtlich entscheidende Grenze verläuft zum Plugin
(siehe ADR 0002) und bleibt hart.

Was die mittlere Stufe trotzdem verhindert, ist der Zugriff auf fremde Entities
und Repositories — die Kopplung, die sich später nicht mehr auflösen lässt, weil
sie das Datenbankschema eines Moduls zur öffentlichen Fläche macht.

## Konsequenzen

- Ein neues Modul braucht zwei Deptrac-Schichten und einen Ruleset-Eintrag.
- Cross-Module-Lesezugriffe laufen über Application-Services, nicht über
  Repositories.
- Hub-Module wie `Composition` im Referenzsystem werden überflüssig: ein
  Controller ruft schlicht mehrere Application-Services auf.
- Eine spätere Verschärfung auf Contract-only ist mechanisch — Deptrac-Config
  umstellen, dann zeigt der Compiler jede Stelle, die zu heben ist.
