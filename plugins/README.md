# Plugins

Plugins laufen als **eigene Prozesse** im Anwendungscontainer. Sie teilen
weder Autoloader noch Request-Kontext mit dem Core und sprechen ausschließlich
über die öffentliche Grenze, die in
[`../docs/architecture/plugin-boundary.md`](../docs/architecture/plugin-boundary.md)
beschrieben ist.

Jedes Plugin ist ein eigenes Composer-Projekt:

```
plugins/<name>/
  manifest.json      was das Plugin ist und will
  composer.json      eigene Abhängigkeiten, eigener Autoloader
  public/index.php   der Eingang
  src/
```

**Kein eigener Container.** Der Core startet jedes aktivierte Plugin selbst als
eigenen PHP-Prozess auf einem eigenen Port, überwacht ihn und beendet ihn, wenn
das Plugin ausgesetzt wird. Wer ein Plugin betreibt, legt das Verzeichnis ab
und aktiviert es in den Einstellungen — mehr nicht.

Jedes Plugin läuft unter einer eigenen Benutzerkennung und hinter einer
Netzsperre: es erreicht die Schnittstelle des Cores und seinen eigenen
Datenbankspeicher, das Internet nur mit `"internet": true` im Manifest und der
Zustimmung beim Aktivieren. Die Einzelheiten stehen in der Plugin-Grenze.

**Warum trotzdem ein eigener Prozess:** Ein Plugin, das Core-Klassen lädt, wäre
mit dem Core zu einem einzigen Werk verbunden — und müsste dann selbst unter
AGPLv3 stehen. Die Prozesstrennung ist der Grund, warum ein Dritter ein
kommerzielles Plugin gegen den AGPLv3-Core bauen kann.

**Ein Repository, nicht mehrere.** Die Plugins dieses Projekts liegen hier.
Dritte entwickeln ihre selbstverständlich in ihren eigenen Repositories.

Das Referenz-Plugin ist [`reporting`](reporting/README.md): Berichte eignen
sich dafür, weil sie **als sie selbst handeln** — eigenes Token, lesen viel,
schreiben nichts zurück. Ein Plugin, das den Core bitten müsste, ihm eine
fremde Identität zu glauben, würde die Grenze zur Behauptung machen.
