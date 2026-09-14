<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->
<!-- SPDX-FileCopyrightText: 2026 s3gv -->

# ADR 0003 — Geld als Integer-Cents

- **Status:** Akzeptiert
- **Datum:** 2026-09-09

## Kontext

ImmoBase rechnet Nebenkosten ab, verteilt Beträge nach Schlüsseln und stellt
Forderungen. Jede Zahl in einer Abrechnung muss aufgehen: die Summe der Teile
muss exakt dem Ganzen entsprechen, und jeder Betrag muss herleitbar sein.

Fließkomma kann Beträge wie 0,10 Euro nicht exakt darstellen. In einer
Abrechnung summieren sich solche Fehler zu Beträgen, die nicht aufgehen — und
das fällt erst auf, wenn ein Mieter nachrechnet.

## Entscheidung

Geld wird ausschließlich als `App\Shared\Money\Money` geführt, intern ein
Integer in Cent. Es gibt **keinen** Konstruktor aus Float, und `float` taucht in
keiner Signatur auf, die mit Geld zu tun hat.

Aufteilungen laufen über `Money::allocate()`. Jeder Teil bekommt seinen
abgerundeten Anteil, die verbleibenden Cent werden der Reihe nach von vorne
verteilt. Die Summe der Teile entspricht damit immer exakt dem Ausgangsbetrag.

## Konsequenzen

- Prozentrechnung und Schlüsselverteilung laufen über `allocate` mit
  ganzzahligen Gewichten, nicht über Multiplikation mit einem Faktor.
- Negative Beträge werden über das Vorzeichen behandelt, damit auch
  Gutschriften ohne Verlust aufgeteilt werden können.
- Wer einen Betrag anzeigen will, formatiert ihn an der Oberfläche; das
  Wertobjekt kennt keine Darstellung.
- Dass es kein `fromFloat` gibt, wird nicht durch einen Test abgesichert,
  sondern durch diese Entscheidung und die Prüfung im Code-Review. Ein Test auf
  die Abwesenheit von Code kann statische Analyse besser.
