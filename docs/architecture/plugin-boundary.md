# ImmoBase Plugin API v1

Diese Seite beschreibt die öffentliche Grenze zwischen dem ImmoBase-Core und
Plugins. Sie ist eine Zusage: was hier steht, bleibt innerhalb von v1 stabil.

## Der Grundsatz

**Keine PHP-Klasse des Cores ist öffentliche API.**

Ein Plugin lädt niemals Core-Code, teilt keinen Autoloader und läuft nie im
selben Request. Es ist ein eigener Prozess und spricht über HTTP mit dem Core.

**Kein eigener Container.** Der Core startet jedes aktivierte Plugin selbst als
eigenen PHP-Prozess im Anwendungscontainer — auf einem eigenen Port, an
`127.0.0.1` gebunden —, überwacht ihn, startet ihn nach einem Absturz neu und
beendet ihn, wenn das Plugin ausgesetzt oder entfernt wird. Der Prozess erbt
die Umgebung des Cores **nicht**: er bekommt `PATH`, die Adresse des Cores und
sein Token, sonst nichts — insbesondere nicht die Datenbankverbindung des
Cores.

**Jedes Plugin läuft unter einer eigenen Benutzerkennung** (100000 plus Port),
weder unter der des Cores noch unter einer gemeinsamen. Es liest damit weder
die Prozessumgebung des Cores noch das Token eines anderen Plugins, beendet
keine fremden Prozesse und sieht nichts unter `var/` — nicht die Sitzungen,
nicht die Protokolle. Sein Zuhause liegt in `/tmp/immobase-plugin-<kennung>`,
nur für es selbst lesbar. Den Wechsel übernimmt ein kleiner Helfer
(`docker/plugin-runner/`), der nur in diesen Bereich wechseln kann und nur vom
Core aufgerufen werden darf.

**Und jedes steht hinter einer Netzsperre.** Plugins teilen sich das Netz des
Anwendungscontainers; ohne Sperre erreichten sie die Datenbank mit jedem Konto,
das sie erraten, die Mails, die ganze Anwendung auf localhost und in einer Cloud
die Metadaten mit den Zugangsdaten der Maschine. Erreichbar ist deshalb:

- der Core über einen eigenen Eingang auf `127.0.0.1:2080`, der nur `/api/v1`
  und `/plugin-api` kennt — nicht Anmeldung, nicht Formulare;
- die Datenbank auf ihrem Port, als IP-Adresse; anmelden kann sich das Plugin
  dort nur mit seiner eigenen Rolle;
- das Internet nur, wenn das Manifest `"internet": true` sagt und ein
  Administrator beim Aktivieren zugestimmt hat. Private Netze, Link-Local,
  localhost und Multicast bleiben auch dann gesperrt.

Alles andere lehnt der Kernel sofort ab, IPv4 wie IPv6, auch die Ports anderer
Plugins. Die Regeln schreibt `plugin-firewall` mit `CAP_NET_ADMIN`, das nur
dieser Helfer hält; der Container braucht dafür `cap_add: NET_ADMIN`. Fehlt die
Sperre, startet `run-as-plugin` kein Plugin — es prüft vor jedem Start, dass
eine gesperrte Verbindung auch wirklich abgelehnt wird. Ohne eigenen Container
je Plugin: der bräuchte einen Core mit Zugriff auf den Docker-Socket, und das
wäre root auf dem Rechner.

Das ist keine Stilfrage, sondern die Grundlage des Lizenzmodells. Der Core steht
unter AGPLv3. Ein Plugin, das Core-Klassen lädt, wäre mit ihm zu einem
gemeinsamen Werk verbunden und müsste selbst AGPLv3 sein. Über eine
Netzwerkgrenze bleibt es ein eigenständiges Werk und darf beliebig lizenziert
werden — auch proprietär und kommerziell.

Das gilt ausdrücklich für jeden: nicht nur für Plugins des Projektinhabers,
sondern für jeden Dritten, der eines bauen möchte.

## Die drei Wege

### 1. Lesen — HTTP-JSON-API

Unter `/api/v1/`, mit einem Token im `Authorization: Bearer`-Kopf. Das Token
entsteht bei jedem Start des Plugin-Prozesses neu und geht ihm über seine
Umgebung zu; gespeichert ist nur sein Abdruck, und niemand muss es
abschreiben.

**Englisch.** Es ist die einzige Fläche, die fremde Entwickler lesen, und der
Code ist ohnehin englisch — die deutschen Adressen der Oberfläche bleiben davon
unberührt.

Es gibt heute diese Ressourcen:

| Ressource | Recht |
|---|---|
| `properties`, `units` | `properties.view` |
| `parties` | `parties.view` |
| `tenancies` | `tenancies.view` |
| `costs`, `cost-kinds`, `loans`, `reserve-movements` | `finance.view` |
| `claims` | `dunning.view` |
| `statements` | `billing.view` |

Jede antwortet auf `GET /api/v1/<name>?page=N` mit
`{"data": […], "page": …, "pages": …, "total": …}` und auf
`GET /api/v1/<name>/<id>` mit einem einzelnen Datensatz. Fünfzig je Seite.

Dazu drei Auskünfte über einen selbst: `GET /api/v1/plugins/self`,
`GET /api/v1/plugins/self/storage` und `PUT /api/v1/plugins/self/tiles`.

**Was das Recht angeht, gibt es keine zweite Wahrheit.** Wer „Kosten" über die
Schnittstelle liest, braucht `finance.view` — dasselbe Recht wie in der
Oberfläche. Geprüft wird an einer einzigen Stelle für alle Ressourcen, und ein
Test geht den ganzen Katalog durch und verlangt von jeder dieselbe Absage.

**Geld geht als Dezimalzeichenkette hinaus**, nie als JSON-Zahl:
`{"amount": "1234.56", "currency": "EUR"}`. JSON kennt nur `number`, und das
ist in den meisten Sprachen ein Double — eine Auswertung, die damit summiert,
geht ab der vierten Stelle nicht mehr auf.

**Was nicht hinausgeht:** eine Restschuld als fertige Zahl (dafür die Vorgänge,
aus denen sie folgt), ein Rücklagenstand (dafür die Bewegungen) und ein
Abrechnungsbetrag. Erfundene Bezugsgrößen sind schlechter als keine.

### 2. Reagieren — ausgehende Ereignisse

Der Core stellt jede Änderung als Webhook zu: `POST` auf den Pfad aus dem
Manifest, mit `X-ImmoBase-Event`, `X-ImmoBase-Delivery` und
`X-ImmoBase-Signature`. Unterschrieben wird per HMAC-SHA256 über den Rumpf, mit
dem SHA-256-Abdruck des eigenen Tokens als Schlüssel — beide Seiten kennen ihn,
und das Token selbst geht nie über die Leitung.

Die Nutzlast trägt **keine Fachdaten**, nur den Hinweis:

```json
{ "event": "costs.updated", "resource": "costs", "id": "0192…", "at": "2026-09-14T09:12:00+02:00" }
```

Mit Daten wäre sie eine zweite Stelle, an der Berechtigungen geprüft werden
müssten — und damit eine zweite, an der man sie vergisst. Was es war, holt das
Plugin über die API.

**Selbstbegrenzende Regel:** Es gibt Ereignisse genau für die Ressourcen, die
es auch zu lesen gibt, und nur für die, die dieses Plugin lesen darf. Abonniert
**und** freigegeben, nicht eines von beiden.

Angemeldet wird im Manifest mit Mustern: `costs.*`, `*.created`, `*.*`. Die
Aktionen sind `created`, `updated` und `deleted`.

Zugestellt wird aus einer Ablage und nicht mitten im Speichern: sonst hätte die
Erreichbarkeit eines fremden Prozesses etwas damit zu tun, ob eine
Kostenposition abgelegt werden kann. Fehlschläge werden mit wachsendem Abstand
wiederholt (1, 5, 25, 125 Minuten), nach fünf Versuchen aufgegeben und nach
einer Woche verworfen. Ein ausgesetztes Plugin bekommt nichts — auch nicht
nachträglich.

### 3. Erscheinen — Manifest, eigene Seiten, Kacheln

Ein Plugin meldet über sein Manifest an, welche Menüeinträge es beisteuert. Der
Core zeigt sie unter einer eigenen Rubrik in der Seitenleiste; jeder Eintrag
hängt an einem Recht, das dasselbe Plugin mitbringt. Die Rubrik rollt für sich,
wenn die Liste lang wird — die Menüpunkte der Anwendung schrumpfen nicht.

**Der Core holt die Seite selbst.** Ein Menüpunkt führt auf eine Adresse des
Cores; der prüft dort Sitzung und Recht wie bei jeder anderen Seite, holt den
Inhalt serverseitig beim Plugin und zeigt ihn in seiner Shell. Der Browser
spricht nie mit dem Plugin — kein zweites Passwort, kein CORS, kein iframe.
Geliefert wird ein **Fragment**: kein `<html>`, kein `<head>`. Fünf Sekunden,
ein Mebibyte, keine Weiterleitungen; antwortet es nicht, steht eine ruhige
Seite da und die Anwendung läuft weiter.

Ein Menüeintrag deckt seinen Pfad und alles darunter ab. Was unter keinem
Eintrag liegt, gibt es nicht: ein Plugin kann damit keine Seite ausliefern, die
niemand bestätigt hat. Pfade mit Punkt-Segmenten, leeren Segmenten, Backslash,
`%`, `?` oder `#` reicht der Core gar nicht erst weiter — sie könnten unterwegs
zu einem anderen Pfad werden als dem, dessen Recht geprüft wurde. Die
Query-Parameter der Adresse gehen unverändert mit; was das Plugin daraus
ausgibt, muss es selbst maskieren.

**Skripte im Fragment laufen nicht.** Die Content-Security-Policy des Cores
lässt nur Skripte von hier und mit der Nonce der Anfrage zu. `<style>`-Blöcke
des Fragments bekommen die Nonce, Stil-Attribute sind erlaubt, Bilder und
Schriften nur vom Core oder als `data:`. Wer Interaktion braucht, verlinkt auf
eigene Seiten oder schickt Formulare an den Core.

**Rechte liegen im eigenen Bereich.** Ein Bereich heißt wie das Plugin oder
beginnt mit dessen Namen und einem Unterstrich; ein Plugin heißt nicht wie ein
Bereich der Anwendung. Sonst stünde seine Beschriftung in der Rechtematrix
dort, wo ein Administrator etwas anderes vermutet.

Wer sein Plugin außerhalb dieses Netzes betreibt und ganze Seiten ausliefert,
bindet das Stylesheet des Cores unter `/plugin-api/v1/theme.css` ein. Es wird
bei jeder Anfrage zurückgefragt (`Cache-Control: public, no-cache` mit ETag):
eine Änderung am Designsystem ist beim nächsten Abruf sichtbar, und ist nichts
passiert, kostet der Abruf ein 304 ohne Rumpf.

**Kacheln** stellt ein Plugin mit `PUT /api/v1/plugins/self/tiles` auf die
Übersicht — geschoben und nicht abgeholt, damit die Startseite beim Aufbau
niemanden fragen muss. Was älter als vierundzwanzig Stunden ist, verschwindet
still: eine Kachel mit einer toten Zahl ist schlimmer als keine.

## Das Manifest

`plugins/<name>/manifest.json` ist die einzige Stelle, an der ein Plugin sagt,
was es ist und will: `api`, `name`, `version`, `label`, `permissions`,
`reads`, `nav`, `tables`, `events`, `webhook_path`, `internet`. Wo es läuft, sagt es nicht:
den Port vergibt der Core. Der Aufbau
im Einzelnen steht in [`../../plugins/README.md`](../../plugins/README.md).

Der Grund, warum alles dort steht: die Aktivierung kann dann eine ehrliche
Frage stellen. Was ein Plugin erst zur Laufzeit verlangt, hat niemand
bestätigt.

Ein Manifest, das nicht passt, verschwindet nicht still — es steht in den
Einstellungen mit der Stelle, an der es hakt: `nav[0].path` und nicht
„ungültiges Manifest".

## Aktivieren heißt vertrauen

Was unter `plugins/` liegt, ist **gefunden** — und sonst nichts: keine Rechte,
keine Menüpunkte, keine Tabellen, keine Seite. Erst in *Einstellungen →
Plugins* wird daraus eine Installation.

Die Seite dort zeigt vor dem Knopf, welche Rechte das Plugin mitbringt, welche
Daten sein Token lesen darf, welche Tabellen entstehen und welche Ereignisse
es bekommt. Darüber steht, was das bedeutet: *Ein Plugin ist
fremde Software.* Der Knopf geht erst auf, wenn jemand ausdrücklich erklärt,
dass er die Herkunft kennt und ihr vertraut. Wer das war, steht danach im
Änderungsprotokoll.

Verlangt eine neue Fassung **mehr** als die bestätigte — neue Rechte, neue
Lesebereiche —, wird erneut gefragt. Sonst schliche sich ein Plugin über
Fassungen hinweg Rechte an, die nie jemand gegeben hat.

Drei Zustände: gefunden, aktiv, ausgesetzt. Ausgesetzt heißt: kein Prozess,
keine Menüpunkte, keine Kacheln, keine Zustellungen, Token gesperrt — Daten
bleiben.
Entfernen nimmt das Schema mit, und die Rückfrage sagt das auch.

## Der Speicher

Kein Plugin bringt eine eigene Datenbank mit — nach zwanzig Plugins wären das
zwanzig Datenbanken. Es meldet im Manifest die Tabellen an, die es braucht; sie
entstehen in einem eigenen Schema `plugin_<name>`, und dazu entsteht eine
Datenbankrolle, die **nur dort** lesen und schreiben darf und dort auch nichts
anlegen kann.

Die Einschränkung ist der Punkt: käme ein Plugin an die Tabellen des Cores,
wäre `/api/v1/` sinnlos und das interne Schema über Nacht öffentliche Zusage —
ab dann ließe sich keine Spalte mehr umbenennen, ohne fremde Plugins zu
brechen.

Typensatz: `text`, `integer`, `decimal`, `boolean`, `timestamp`, `uuid`. Kein
rohes SQL aus einem Manifest.

**Innerhalb von v1 wächst das Schema nur.** Eine neue Fassung bekommt neue
Tabellen und neue Spalten; weggefallene bleiben stehen, bis das Plugin entfernt
wird. Eine Aktualisierung, die Daten löscht, ist eine Falle.

Die Zugangsdaten holt sich das Plugin mit seinem Token bei
`/api/v1/plugins/self/storage`. Der Betreiber konfiguriert gar nichts.

Das Passwort der Datenbankrolle liegt dafür lesbar in der Tabelle des Cores —
der Core muss es herausgeben können. Wer die Datenbank des Cores hat, hat damit
auch die Schemata der Plugins; eine Verschlüsselung mit einem Schlüssel neben
der Datenbank änderte daran nichts. Jede Rolle darf nur ihr eigenes Schema.

## Versionierung

`api: 1` im Manifest; ein unbekannter Stand lässt sich nicht aktivieren — eine
künftige Fassung darf Felder anders meinen, und ein alter Leser würde sie
falsch verstehen statt sie abzulehnen.

Innerhalb von v1 wird nur erweitert: neue Ressourcen, neue Felder, neue
Manifestangaben. Was es gibt, bleibt. Eine brechende Änderung bekommt
`/api/v2/`, und `v1` bleibt für eine angekündigte Frist bestehen.

## Ohne Plugin ist der Core vollständig

Kein aktiviertes Plugin heißt: keine Menüpunkte, keine Rubrik, keine Rechte im
Katalog, keine Kacheln, keine Routen, keine Zustellungen, keine Schemata. Ein
Funktionstest hält genau das.

Zwei weitere Zusicherungen stehen als Test da: **keine ladbare Datei unter
`plugins/` nennt den Namensraum des Cores** — das ist die Lizenzgrenze selbst —,
und **keine Ressource antwortet ohne Token**, der ganze Katalog durchgegangen.

## Der erste Abnehmer: Auswertungen

Gebaut und in Betrieb: `plugins/reporting/` ist ein eigenes Composer-Projekt
mit eigenem Prozess, eigenem `vendor/` und eigenem Schema. Es liest über
`/api/v1/`, hält sich per Webhook aktuell, liefert unter einem Menüpunkt
„Berichte" vier Auswertungen mit Diagrammen und schiebt vier Kacheln auf die
Übersicht.

Es ist damit der Beweis, dass die Grenze trägt, statt sie nur zu behaupten —
und die Vorlage für jeden, der ein eigenes baut.

Es eignet sich dafür aus einem bestimmten Grund: **es handelt als es selbst.**
Ein Auswertungs-Plugin meldet sich mit seinem eigenen Token an, liest und
schreibt nichts zurück. Es muss den Core nie bitten, ihm eine fremde Identität
zu glauben.

## Warum das Kundenportal es nicht ist

Ursprünglich war das Kundenportal als Referenz-Plugin vorgesehen. Diese
Entscheidung ist zurückgenommen — das Portal wird ein Modul des Cores.

Der Grund ist nicht seine Größe, sondern die Identität. Über eine
Netzwerkgrenze weiß der Core nicht, wer im Portal sitzt; er kennt nur das
Plugin und dessen Token. Damit ein Mieter seine Abrechnung sehen kann, müsste
das Plugin erklären „ich handle für Mieter 4711", und der Core müsste das
glauben. Ein Plugin, dem man das glaubt, ist kein eigenständiges Programm mehr
— es ist ein Teil des Systems mit einem Netzwerkkabel in der Mitte, und die
Grenze wäre eine Behauptung statt einer Zusage.

Dieselbe Beobachtung an zwei weiteren Stellen:

- **Dokumentenfreigaben.** Die Verwaltung entscheidet im Core, wer welches
  Dokument sehen darf. Durchgesetzt werden müsste die Entscheidung im Portal,
  für einen Nutzer, den der Core nicht kennt.
- **Anliegen.** Sie entstehen im Portal, bearbeitet werden sie im Core. Läge
  ihre Wahrheit im Plugin, wäre der Core ohne dieses Plugin unvollständig — das
  ist die falsche Abhängigkeitsrichtung für etwas, das ergänzen und nicht
  tragen soll.

Der eigene Sicherheitskontext, das stärkste Argument für die Trennung, braucht
dafür keine Prozessgrenze: eine zweite Firewall in derselben Anwendung leistet
dasselbe.

Als Faustregel für alles Weitere: **was eine fremde Identität stellvertretend
braucht oder was der Core zum Arbeiten braucht, gehört in den Core.** Ein
Plugin ergänzt; es trägt nicht.
