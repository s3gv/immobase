<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->
<!-- SPDX-FileCopyrightText: 2026 s3gv -->

# ADR 0006 — E-Rechnung selbst geschrieben, geprüft ohne Java

- **Status:** Akzeptiert
- **Datum:** 2026-09-14

## Kontext

Ab 2027/2028 müssen Unternehmen Rechnungen an andere Unternehmen im Inland als
E-Rechnung ausstellen. In ImmoBase betrifft das zwei Belege, beide nur bei
Gewerbemietverhältnissen mit Option zur Umsatzsteuer (§ 9 UStG): die
Dauermietrechnung und die Betriebskostenabrechnung. Wohnraummiete (§ 4 Nr. 12
UStG) und Hausgeld (§ 4 Nr. 13 UStG) sind dauerhaft ausgenommen.

Die Betriebskostenabrechnung kannte bis dahin keine Umsatzsteuer. Wer optiert,
legt aber netto um und rechnet den Satz darauf; die Abrechnung ist eine
Endrechnung und setzt die Vorauszahlungen samt ihrer Steuer ab (§ 14 Abs. 5
UStG).

Für XRechnung gibt es PHP-Bibliotheken. Die verbreitete zieht einen PDF-Parser
unter LGPL nach, ihr Nachfolger mPDF unter GPL. Der amtliche Prüfdienst der
KoSIT ist ein Java-Programm; seine Geschäftsregeln liegen als Schematron in
XSLT 2.0 vor, das PHP nicht ausführen kann.

## Entscheidung

- **Umsatzsteuer in der Abrechnung.** Kostenjahre tragen „darin enthaltene
  Umsatzsteuer". Optierte Mietverhältnisse werden netto abgerechnet, die Steuer
  einmal auf die Nettosumme gerechnet, die Vorauszahlungen brutto abgezogen und
  ihre Steuer ausgewiesen. Alle anderen Schreiben bleiben bytegenau, wie sie waren.
- **XRechnung 3.0 in CII, selbst geschrieben.** Ein neutraler Wert in
  `Shared\EInvoice`, ein Schreiber, der nur die Elementfolge des Schemas D16B
  kennt, und je Beleg eine Abbildung aus dem, was beim Ausstellen eingefroren
  wurde. CII statt UBL, weil ZUGFeRD dieses XML in ein PDF einbettet.
- **Was eine E-Rechnung zusätzlich braucht, wird eingefroren** — Anschriften in
  Teilen, Käuferreferenz und Adresse des Mieters, Ansprechpartner der
  Verwaltung, Zahlungsabrede samt Lastschrift. Fehlt etwas, hält das Ausstellen
  benannt auf, wie bei einer fehlenden Steuernummer.
- **Geprüft ohne Java.** Referenzdateien für jeden Zweig, bytegenau verglichen.
  Jede Referenzdatei und jedes in den Tests erzeugte XML besteht das CII-Schema
  D16B (`DOMDocument::schemaValidate()`; die Schemadateien liegen unverändert
  unter `tests/Fixture/xsd/cii-d16b/`, wie UN/CEFACT sie herausgibt). Die für
  diese Belege einschlägigen Geschäftsregeln aus EN 16931 und der XRechnung-CIUS
  sind in PHP nachgebildet (`tests/Shared/EInvoice/XRechnungRules.php`), jede an
  einer verdorbenen Datei rot gesehen.

## Konsequenzen

- **Die Prüfung ist nicht der amtliche Prüfdienst.** Das Schema ist das
  offizielle, die Geschäftsregeln sind nachgebildet — und zwar die, die für eine
  Dauermietrechnung und eine Betriebskostenabrechnung greifen können. Rabatte, Zuschläge, andere Steuerkategorien und Anhänge erzeugt
  ImmoBase nicht; Regeln dafür gibt es darum auch nicht. Kommt ein Beleg mit
  solchen Angaben hinzu, wächst die Nachbildung mit.
- **Der Zahlbetrag ist der Saldo vom Blatt.** Bei einer korrigierten Abrechnung
  zählt der bereits abgerechnete Saldo mit den Vorauszahlungen als schon
  gedeckt (BT-113); gefordert wird die Differenz. Ein Guthaben wird nie als
  Lastschrift ausgezeichnet: es geht auf das Konto zurück, von dem der Mieter
  per Lastschrift zahlt, und ohne bekanntes Konto gibt die Rechnung keinen
  Zahlungsweg vor (UNTDID 4461 Code 1) — nie das Konto des Vermieters.
- **Ältere Belege bekommen keine E-Rechnung.** Sie tragen die zusätzlichen
  Angaben nicht; eine E-Rechnung entsteht mit der nächsten Fassung
  (Dauermietrechnung) oder Korrektur (Abrechnung).
- **Empfang und Aufbewahrung bleiben beim Verwalter.** ImmoBase stellt aus,
  verschickt aber nicht und archiviert nicht revisionssicher. Eingehende
  E-Rechnungen sind ein eigenes Vorhaben.
- **Ändert sich eine Referenzdatei, ist das eine Änderung an dem, was beim
  Mieter ankommt.** Sie wird angesehen und nicht einfach neu erzeugt.
