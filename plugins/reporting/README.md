# Reporting — das Referenz-Plugin

Dieses Verzeichnis ist die Vorlage. Wer ein eigenes Plugin für ImmoBase baut,
kann sich hier abschauen, wie eines aussieht — und was es ausdrücklich **nicht**
tut.

## Der Grundsatz in einem Satz

Ein Plugin ist ein **eigener Prozess** — kein eigener Container, aber ein
eigener PHP-Prozess im Anwendungscontainer, den der Core startet und
überwacht. Es lädt keine Klasse des Cores, teilt keinen Autoloader und läuft
nie im selben Request.

Das ist die Lizenzgrenze: der Core steht unter AGPLv3, und was seine Klassen
lädt, wäre mit ihm ein gemeinsames Werk und selbst AGPL-pflichtig. Über die
Netzwerkgrenze bleibt ein Plugin ein eigenständiges Werk und darf beliebig
lizenziert werden — auch proprietär. Dieses hier ist AGPL, weil es zum Projekt
gehört; Ihres muss das nicht sein.

Ein Test im Core hält die Grenze: **keine Datei unter `plugins/` nennt den
Namensraum `App\`.**

## Aufbau

```
plugins/<name>/
  manifest.json    was das Plugin ist und will — die einzige Stelle dafür
  composer.json    eigene Abhängigkeiten, eigener Autoloader
  public/index.php der Eingang
  src/             eigener Code
```

Der Verzeichnisname ist der Name des Plugins. Er muss mit dem `name` im
Manifest übereinstimmen: er trägt später das Datenbankschema, die Adressen und
die Rechte.

## Installieren

1. Verzeichnis nach `plugins/` legen.
2. In ImmoBase **Einstellungen → Plugins** öffnen. Das Plugin steht dort als
   *Gefunden*.
3. Auf *Aktivieren* gehen. Die Seite zeigt, welche Rechte das Plugin mitbringt,
   welche Daten sein Token lesen darf, welche Tabellen entstehen und welche
   Ereignisse es bekommt. Erst nach der Vertrauensmarke lässt sich der Knopf
   drücken.

Das ist alles. Wenige Sekunden später startet der Core das Plugin als eigenen
Prozess — im Image sind seine Abhängigkeiten schon installiert, in der
Entwicklung holt der Core `composer install` einmal nach. Scheitert das, startet
das Plugin nicht; das Ende der Composer-Ausgabe steht in der Meldung des
Hintergrundlaufs, die ganze in `var/plugins/<name>.composer.log`.

Aussetzen beendet den Prozess und hält die Daten. Entfernen nimmt das Schema
mit — die Rückfrage sagt das auch.

### Was der Prozess mitbekommt

Genau zwei Werte in seiner Umgebung, dazu `PATH`: `IMMOBASE_URL` (wie er den
Core erreicht) und `IMMOBASE_TOKEN`. **Sonst nichts** — insbesondere nicht die
Datenbankverbindung des Cores. Das Token ist bei jedem Start ein neues;
niemand schreibt es ab, niemand trägt es irgendwo ein.

Die Zugangsdaten zur eigenen Datenbank holt sich das Plugin damit selbst bei
`/api/v1/plugins/self/storage`. Sie führen in sein eigenes Schema und nirgends
sonst hin.

### Was der Prozess im Netz erreicht

Im Image steht jedes Plugin hinter einer Netzsperre. Erreichbar sind
`IMMOBASE_URL` — ein eigener Eingang des Cores, der nur `/api/v1` und
`/plugin-api` kennt — und die Datenbank auf ihrem Port, als IP-Adresse in den
Zugangsdaten. Sonst nichts: keine anderen Dienste, keine anderen Plugins, kein
Namensdienst, kein Internet. Abgelehnte Verbindungen enden sofort mit „No route
to host".

Wer das Internet braucht, sagt es im Manifest mit `"internet": true`. Der
Administrator sieht das beim Aktivieren als eigene Zeile. Auch dann bleiben
private Netze, Link-Local (Metadaten einer Cloud) und die übrigen Dienste der
Installation gesperrt.

### Wann gespiegelt wird

Das Plugin braucht **keinen Dauerlauf** neben seinem Server. Gespiegelt wird, wenn
ein Webhook kommt und der Spiegel vor der gemeldeten Änderung geholt wurde, und
wenn eine Seite aufgerufen wird, deren Spiegel älter als eine Viertelstunde ist.
Nach jeder Spiegelung gehen die vier Kacheln an den Core.

Holen und Schreiben laufen unter einer Sperre. Kommen zwei Anstöße zugleich,
wartet der zweite und schaut danach nach, ob der eben geschriebene Stand schon
enthält, weswegen er gekommen ist — so überschreibt nie ein älterer Abruf einen
neueren, und hundert Meldungen einer Massenänderung holen den Bestand nicht
hundertmal.

Bei größeren Beständen wäre der nächste Schritt, nur das Geänderte zu holen —
die Kennung steht in jedem Webhook. Für eine Hausverwaltung ist der ganze
Bestand klein genug, und ein halb gespiegelter Stand ergäbe Zahlen, die nicht
aufgehen.

## Das Manifest

```json
{
    "api": 1,
    "name": "reporting",
    "version": "1.1.0",
    "label": { "de": "Berichte", "en": "Reports" },
    "permissions": [
        { "area": "reporting", "actions": ["view"],
          "label": { "de": "Berichte", "en": "Reports" } }
    ],
    "reads": ["finance.view"],
    "nav": [
        { "path": "/berichte", "permission": "reporting.view", "icon": "chart",
          "label": { "de": "Berichte", "en": "Reports" } }
    ],
    "tables": [
        { "name": "cost_mirror", "columns": [
            { "name": "id", "type": "uuid", "primary": true },
            { "name": "amount", "type": "decimal" }
        ] }
    ],
    "events": ["costs.*"],
    "webhook_path": "/webhook"
}
```

| Feld | Bedeutung |
|---|---|
| `api` | Der Stand der Schnittstelle. Heute `1`. Ein unbekannter Stand lässt sich nicht aktivieren. |
| `permissions` | Die **eigenen** Rechte des Plugins. Sie gehen in denselben Katalog wie die der Module. |
| `reads` | Was sein Token über `/api/v1/` lesen darf — Rechteschlüssel des Cores. |
| `nav` | Menüpunkte. Der Pfad ist der Weg **beim Plugin**; der Core hängt ihn an seine eigene Proxy-Adresse. Ein Eintrag deckt seinen Pfad und alles darunter ab. |
| `tables` | Tabellen im eigenen Schema `plugin_<name>`. Typen: `text`, `integer`, `decimal`, `boolean`, `timestamp`, `uuid`. |
| `events` | Welche Ereignisse als Webhook zugestellt werden sollen. |
| `internet` | `true`, wenn das Plugin öffentliche Adressen im Internet erreichen muss. Ohne Angabe: nein. Verlangt eine neue Fassung es neu, braucht sie eine neue Zustimmung. |

Ein Manifest, das nicht passt, verschwindet nicht still: es steht in den
Einstellungen mit der Stelle da, an der es hakt — `nav[0].path` und nicht
„ungültiges Manifest".

## Was der Core zusagt

**Seiten.** Ein Menüpunkt führt auf eine Adresse des Cores. Der Core prüft dort
Sitzung und Recht, holt den Inhalt **serverseitig** beim Plugin und zeigt ihn in
seiner Shell. Der Browser spricht nie direkt mit dem Plugin, es gibt kein
zweites Passwort und kein CORS. Geliefert wird ein **Fragment** — kein `<html>`,
kein `<head>`. Fünf Sekunden, ein Mebibyte, keine Weiterleitungen. Das
Stylesheet des Cores liegt zusätzlich unter `/plugin-api/v1/theme.css`.

**Der Prozess** lauscht an `127.0.0.1` auf einem Port, den der Core beim
Aktivieren vergibt — von außerhalb des Containers ist er nicht erreichbar.
Stürzt er ab, startet der Core ihn neu, mit einer Pause dazwischen.

**Speicher.** Beim Aktivieren entstehen das Schema `plugin_<name>` und eine
Datenbankrolle, die **nur dort** lesen und schreiben darf. An die Tabellen des
Cores kommt sie nicht — Fachdaten gibt es über `/api/v1/`, und nur so bleibt
unser internes Schema änderbar, ohne fremde Plugins zu brechen. Die
Zugangsdaten holt sich das Plugin mit seinem Token.

**Versionierung.** Innerhalb von `v1` wird nur erweitert: neue Ressourcen, neue
Felder, neue Manifestangaben. Was es gibt, bleibt. Eine brechende Änderung
bekommt `/api/v2/`, und `v1` bleibt für eine angekündigte Frist bestehen. Das
Schema eines Plugins wächst mit: neue Tabellen und Spalten entstehen, weggefallene
bleiben stehen, bis das Plugin entfernt wird.

**Und wenn das Plugin fehlt?** Dann fehlt es. Keine Menüpunkte, keine Rechte,
keine Seiten — und die Anwendung läuft vollständig weiter. Ein Plugin ergänzt;
es trägt nicht.

Die ausführliche Fassung steht in
[`../../docs/architecture/plugin-boundary.md`](../../docs/architecture/plugin-boundary.md).
