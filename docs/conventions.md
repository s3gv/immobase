<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->
<!-- SPDX-FileCopyrightText: 2026 s3gv -->

# Konventionen

Die Regeln, nach denen ImmoBase gebaut wird. Sie gelten für jeden, der hier
mitarbeitet — ob Mensch oder Agent.

Für den Einstieg als Beitragender: [CONTRIBUTING.md](../CONTRIBUTING.md).
Architekturentscheidungen mit Begründung: [`architecture/decisions/`](architecture/decisions/).

## Was ImmoBase ist

Hausverwaltungs-Software für den deutschen Markt: Objekte, Einheiten,
Mietverhältnisse, Nebenkostenabrechnung, WEG-Hausgeld, Mahnwesen.

**Selbst gehostet, eine Installation pro Nutzer.** Ausdrückliche Nicht-Ziele:

- **Kein Multi-Tenant.** Eine Installation bedient eine Verwaltung.
- **Kein SaaS.** Es gibt keinen zentralen Dienst und keinen Lizenzserver.
- **Keine KI auf Kundendaten.** Mieter- und Eigentümerdaten verlassen die
  Installation nicht.

## Module

```
src/
  Shared/                     Money, Events, Basisklassen — von allen nutzbar
  Module/<Name>/
    Contract/                 öffentlich: Interfaces, DTOs, Events
    Application/              öffentlich: Use Cases
    Domain/                   modulintern: Entities, Wertobjekte, Repository-Interfaces
    Infrastructure/           modulintern: Doctrine, externe Adapter
    UserInterface/            modulintern: Controller, Konsolenbefehle, PDF, Twig-Erweiterungen
templates/<bereich>/          die Vorlagen, nach Bereich der Oberfläche
```

**Ein Modul darf aus einem anderen nur `Contract` und `Application` benutzen.**
`Domain`, `Infrastructure` und `UserInterface` sind tabu. `Shared` kennt kein
Fachmodul.

Deptrac erzwingt das; ein Verstoß bricht den Build. Warum genau diese Grenze:
[ADR 0001](architecture/decisions/0001-modulgrenze-ueber-contract-und-application.md).

### Ein neues Modul anlegen

`Auth` ist die Vorlage — dort ist jede Schicht einmal vollständig ausgeführt.

1. Die vier Verzeichnisse anlegen.
2. In `deptrac.dist.yaml` **zwei** Schichten eintragen (`<Modul>Public` aus
   Contract und Application, `<Modul>Internal` aus dem Rest) und beide im
   `ruleset` verdrahten.
3. Hat das Modul Entities: einen eigenen Eintrag in
   `config/packages/doctrine.yaml`.
4. Schnittstellen in `config/services.yaml` auf ihre Umsetzung zeigen lassen.

### Events statt Zyklen

Es gibt **keine** Modul-Hierarchie. Würde ein synchroner Aufruf einen Zyklus
zwischen zwei Modulen erzeugen, wird daraus ein Domain-Event.

Events implementieren `App\Shared\Event\DomainEvent`, liegen im
`Contract\Event\` ihres Moduls und tragen **nur Primitive und Identifikatoren,
niemals Entities** — eine Entity im Event wäre ein Schlupfloch in die Domäne des
sendenden Moduls.

Braucht ein Modul eine **Antwort** von einem, das von ihm abhängt — etwa ob eine
Abrechnung eine Kostenposition hält —, legt es den Vertrag in sein eigenes
`Contract` und das andere Modul erfüllt ihn. So fragen die Finanzen Billing,
ohne Billing zu kennen.

## Sprache im Code

**Alles, was benannt wird, ist englisch:** Klassen, Methoden, Variablen,
Formularfelder, Konsolenbefehle, Dienste, Rechte, Datenbankspalten.

**Deutsch ist, was ein Mensch in der Oberfläche sieht:** Beschriftungen,
Adressen samt Abfrageparametern (`/objekte?abschnitt=anschrift`) und damit auch
die Vorlagen, die wie ihr Abschnitt oder Schritt in der Adresse heißen
(`templates/property/steps/anschrift.html.twig`). Kommentare sind deutsch.

## Geld

Immer `App\Shared\Money\Money`, intern Integer in Cent. **Nie `float`, nie
`round()` auf Beträge.** Aufteilungen laufen über `Money::allocate()`.

Begründung und Konsequenzen: [ADR 0003](architecture/decisions/0003-geld-als-integer-cents.md).

**Eingabe über `MoneyInput::parse()`.** Punkt und Komma werden beide
angenommen und nach Bauart der Zahl gedeutet, nicht nach eingestellter
Sprache — dieselbe Eingabe ergibt überall denselben Betrag. Währungszeichen
und Leerzeichen sind erlaubt, damit ein aus einer Rechnung kopierter Betrag
sich einfügen lässt.

**Anzeige über den Filter `money`** (mit Währungszeichen) oder `money_number`
(ohne, für Eingabefelder). Die Schreibweise folgt der Sprache der Anfrage, das
Währungszeichen steht hinter dem Betrag. Niemals einen Betrag selbst
zusammensetzen.

## Nachvollziehbarkeit

Jede Zahl in einer Abrechnung muss aus ihren Bestandteilen herleitbar sein, und
die Summe der Teile muss exakt dem Ganzen entsprechen. Eine Vereinfachung der
Bedienung darf nie zulasten der Nachvollziehbarkeit gehen.

## Oberfläche

**Alle Formulare und Abläufe sind mehrstufig.** Start → Schritte →
Ergebnis-Vorschau, mit einer Erklärung in Alltagssprache über jedem Schritt.
Kein Fachjargon, keine überladenen Einzelformulare. Dafür gibt es einen
wiederverwendbaren Baustein; ihn zu umgehen erzeugt genau die inselhaften
Oberflächen, die er verhindern soll.

**Kein hartkodierter Anzeigetext.** Alles über `|trans`, jeder Schlüssel in
`messages.de.yaml` **und** `messages.en.yaml`. Deutsch ist Standard, Englisch
wird vollständig gepflegt. Rechtlich feste Begriffe wie Gesetzeszitate bleiben
unabhängig von der Oberflächensprache korrekt.

**Eingegebene Daten bleiben jederzeit änderbar.** Jede erfasste Angabe hat
einen Weg zurück ins Formular — es gibt keinen Zustand, in dem ein Wert nur
noch über die Datenbank zu korrigieren wäre. Wo Daten angezeigt werden, steht
die Aktion `edit` dabei.

**Eine Änderung nennt ihre Folgen, bevor sie geschieht.** Wenn ein geänderter
Wert etwas ungültig macht, das daraus schon entstanden ist, wird das benannt
und der nötige nächste Schritt gleich mit: „Die Betriebskostenabrechnung 2024
stimmt danach nicht mehr, für die betroffenen Einheiten muss eine Korrektur
erstellt werden."

Das ist keine Meldung hinterher. Wer erst nach dem Speichern erfährt, dass eine
Abrechnung ungültig geworden ist, konnte die Entscheidung nicht treffen. Die
Folgen gehören deshalb in die Rückfrage, und zwar in Alltagssprache.

**Übersichten zeigen den laufenden Stand.** Was vorbei ist — beendet,
abgewickelt, archiviert, deaktiviert — steht bereit, aber nicht im Weg; der
Schalter „Vergangene anzeigen" holt es dazu (`App\Shared\Ui\Past`,
`components/past_toggle.html.twig`). Angefangenes bleibt immer sichtbar: ein
Entwurf oder eine offene Einladung ist keine Vergangenheit, sondern Arbeit,
die noch aussteht. Wer im Filter ausdrücklich einen vergangenen Zustand wählt,
bekommt ihn auch ohne Schalter — eine leere Liste wäre eine Nicht-Antwort auf
eine klare Frage. Und was vorbei ist, steht in keiner Auswahl mehr: nicht im
Picker, nicht im Filter, nirgends, wo etwas Neues daran anknüpfen würde.

**Was einmal gelaufen ist, wird nicht gelöscht, sondern beendet.** Gelöscht
wird nur, was nie in Kraft war — der Entwurf, die Fehleingabe. Sobald ein
Datensatz Grundlage einer Abrechnung sein könnte, ist Löschen die falsche
Antwort: abgerechnet wird das vergangene Jahr, manchmal das vorletzte.

**Ein Schritt, der etwas unumkehrbar macht, wird bestätigt** — und der Text
sagt, was unumkehrbar wird, nicht „Sind Sie sicher?".

**Löschende und folgenschwere Aktionen fragen zurück** — über
`components/confirm.html.twig`, das die Folgen aufzählt. Der bestätigende Knopf
ist rot wie das Symbol, das dorthin geführt hat; vorausgewählt ist Abbrechen,
damit die Eingabetaste nichts entfernt, und ein Klick daneben bricht ab. Ohne
Rückfrage löscht nichts.

**Standardaktionen kommen aus dem Vokabular** in `App\Shared\Ui\Action`:
öffnen, bearbeiten, löschen, speichern, hinzufügen, vor, zurück. Dieselbe
Aktion hat überall dasselbe Symbol und denselben Namen; dargestellt wird sie
über `components/action.html.twig`, das die Beschriftung als `aria-label` setzt
und beim Überfahren einen Hinweis zeigt. Für alles, was nicht darunter fällt,
gibt es einen beschrifteten Knopf statt eines zu erratenden Symbols.

**Navigationseinträge über `components/nav_item.html.twig`.** Die Markierung
wird aus dem Routennamen abgeleitet und gilt für die Route selbst und alles,
was mit ihr und einem Unterstrich weitergeht. Deshalb beginnen alle Routen
eines Moduls mit demselben Präfix — sonst markiert ein Eintrag fremde Seiten
mit oder verliert seine Markierung auf der eigenen Unterseite.

**Demo- und Testdaten sind erkennbar fiktiv.** Nichts, was auf reale Objekte,
Adressen oder Personen hindeutet.

## Lizenz und Abhängigkeiten

Jede neue Quelldatei beginnt mit zwei SPDX-Zeilen — siehe
[header-policy.md](licensing/header-policy.md).

Laufzeit-Abhängigkeiten müssen permissiv lizenziert sein. Copyleft darf nur
hinter der Prozessgrenze laufen — siehe
[dependency-policy.md](licensing/dependency-policy.md). Vor jedem
`composer require` die Lizenz prüfen; `bin/check` prüft es danach ohnehin.

## Persönliche Daten im Repository

**Keine persönlichen E-Mail-Adressen — nirgends.** Weder in Dateien noch in
Commit-Metadaten. Für Git wird die GitHub-Noreply-Adresse verwendet; GitHub
verknüpft solche Commits weiterhin mit dem Konto, ohne die echte Adresse
preiszugeben.

Commit-Metadaten sind der leicht zu übersehende Teil: eine Adresse steckt im
Repository, auch wenn sie in keiner Datei auftaucht — und sie lässt sich nicht
zurückholen, sobald jemand geklont hat.

`tools/check-publication-hygiene.php` prüft beides und bricht den Build.
Erlaubt sind Noreply-Adressen und die laut RFC 2606 für Beispiele reservierten
Domänen (`example.org`, `example.com`, `example.net`) — die gehören in Tests
und Dokumentation.

## Sicherheit

Regeln, die aus dem Sicherheitsaudit stehen geblieben sind. Jede hat eine
Lücke hinter sich.

**Im Browser**

- Kein eingebettetes JavaScript, keine `onclick=`-Handler. Die
  Content-Security-Policy (`src/Shared/Http/SecurityHeaders.php`) lässt nur
  Skripte von hier zu; ein Skript im Seitenkopf braucht
  `nonce="{{ csp_nonce() }}"`. Stylesheets als `<link>`, nie als Import in
  einem Modul — den übersetzt die Importmap in eine `data:`-Adresse.
- Nichts von fremden Servern: keine CDN-Schriften, keine Skripte, keine Bilder.
- `|raw` nur für Inhalte, die der Core selbst erzeugt, und für das Fragment
  eines Plugins.

**Adressen**

- Links in E-Mails über `PublicUrls`, nie über `ABSOLUTE_URL` während einer
  Anfrage: deren Host schreibt der Absender.
- Weiterleitungen auf eine Adresse aus der Anfrage nur über `LocalUrl`.
- Adressen nie mit `sprintf` füllen — kodierte Zeichen wie `%C3` gelten dort
  als Format. Für Seitenzahlen gibt es den Platzhalter `__PAGE__`.

**Eingaben**

- Nutzertext in `LIKE` über `LikePattern::containing()` oder `SearchTerm`.
- Reguläre Ausdrücke, die eine ganze Eingabe prüfen, enden mit `$/D`: ohne `D`
  lässt `$` einen Zeilenumbruch am Ende durch.
- Hochgeladene Dateien, die wieder ausgeliefert werden, werden neu kodiert oder
  als `attachment` mit `nosniff` geschickt.

**Konten und Rechte**

- Alles, was etwas ändert, ist ein POST mit CSRF-Token — auch das Abmelden.
- Bremsen hängen am normalisierten Schlüssel (die Adresse, wie das Konto sie
  kennt) und, wo es um Mails an Dritte geht, zusätzlich am Absender.
- Ein neues Passwort entwertet offene Links zum Zurücksetzen und zur
  Adressänderung. Ein eingerichteter zweiter Faktor wird nie überschrieben,
  nur mit einem Code des alten abgeschaltet.
- Wer Rechte vergibt, vergibt nur solche, die er selbst hat.

**Betrieb**

- Plugin-Prozesse laufen unter eigenen Kennungen und sehen nichts unter
  `var/`. Wer dort etwas Neues ablegt, legt es nicht für andere lesbar ab.
- Plugins stehen hinter einer Netzsperre: Schnittstelle des Cores auf
  `127.0.0.1:2080`, die Datenbank, mit Zustimmung das Internet. Wer einem
  Plugin einen neuen Weg öffnen will, öffnet ihn in
  `docker/plugin-runner/plugin-firewall.sh` und im Manifest, nicht nebenbei.
- Keine echten Geheimnisse in committeten Dateien; die `.env` enthält nur
  Entwicklungswerte, und mit denen startet das Image nicht.
- `.dockerignore` ist eine Liste dessen, was hinein darf. Wer ein neues
  Verzeichnis braucht, trägt es dort ein.
- `bin/check` fragt die Sicherheitsmeldungen der PHP- und JS-Abhängigkeiten
  ab (`composer audit`, `importmap:audit`), auch die der Plugins.

## Qualität

**Warnungen aus eigenem Code sind Fehler, Deprecations eingeschlossen.**
`bin/check` klopft jede Prüfung darauf ab: meldet ein Werkzeug eine
PHP-Diagnose, die auf `src/`, `tests/` oder `tools/` zeigt, schlägt der Lauf
fehl — auch wenn das Werkzeug selbst mit 0 endet.

Deprecations, die Fremdbibliotheken untereinander auslösen, sind ausgenommen.
Sie sind nicht behebbar, und ein Fehler, den niemand beheben kann, wird binnen
Wochen umgangen — dann wirkt die ganze Regel nicht mehr.

| Regel | Wert |
|---|---|
| PHPStan | Level max, ohne Baseline |
| Zyklomatische Komplexität | ≤ 8 pro Methode |
| Methodenlänge | ≤ 30 Zeilen |
| Klassenlänge | ≤ 200 Zeilen |
| Verschachtelungstiefe | ≤ 3 |

Überschreitungen werden **umgebaut, nicht unterdrückt**. Keine
`@phpstan-ignore`-Kommentare, keine Baseline.

**Tests:** Jede Funktion in `Domain` und `Application` braucht Unit-Tests — dort
liegt die fachliche Wahrheit. `Infrastructure` und `UserInterface` werden über
Funktionstests abgedeckt, wo das etwas aussagt; ein Test, der nur beweist, dass
Doctrine funktioniert, wird nicht geschrieben. Vorgehen ist test-first.

## Kommandos

| Befehl | Zweck |
|---|---|
| `make setup` | Hooks aktivieren, Abhängigkeiten installieren |
| `make check` | Alle Prüfungen — dasselbe wie der Pre-Push-Hook |
| `make test` | Nur die Tests |
| `make test-db` | Testdatenbank anlegen und migrieren (einmalig) |
| `make dev-user` | Entwicklungskonto anlegen, falls es fehlt |
| `make fix` | Formatierung korrigieren |
| `make up` / `make down` | Entwicklungs-Stack starten und stoppen |

`make down` lässt die Daten stehen. `docker compose down -v` löscht das
Datenbank-Volume und damit alle Konten — dafür gibt es bewusst kein
Make-Ziel. Die Erstinstallation übernimmt `./install.sh`.

Die Entwicklungsumgebung läuft unter `https://immobase.dev-local`.

## Im Zweifel fragen

Wenn eine Anforderung mehrdeutig ist, **fragen statt raten**. Eine falsch
geratene Annahme kostet mehr als eine Rückfrage — besonders bei
Abrechnungslogik, wo ein Fehler erst auffällt, wenn ein Mieter nachrechnet.
