<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

/**
 * Verhindert, dass interne Arbeitsdateien im oeffentlichen Repository landen.
 *
 * Dieses Repository wird veroeffentlicht. Spezifikationen, Plaene,
 * KI-Werkzeugkonfiguration, Editor-Verzeichnisse und Schluesseldateien gehoeren
 * nicht hinein — teils weil sie interne Entscheidungen offenlegen, teils weil
 * sie schlicht niemanden etwas angehen.
 *
 * .gitignore allein reicht dafuer nicht: eine Datei, die einmal getrackt ist,
 * bleibt getrackt, egal was spaeter in .gitignore steht. Und beim Neuanlegen
 * des Repositories vor der Veroeffentlichung ist genau das der Moment, in dem
 * so etwas durchrutscht. Diese Pruefung schaut deshalb auf den tatsaechlichen
 * Git-Index, nicht auf .gitignore.
 *
 * Geprueft wird viererlei:
 *
 *   1. Welche Dateien getrackt sind (interne Arbeitsdateien, Schluessel,
 *      Editor-Konfiguration).
 *   2. Ob in getrackten Dateien eine persoenliche Adresse steht.
 *   3. Ob die Git-Historie eine solche Adresse als Autor oder Committer traegt.
 *   4. Ob eine interne Datei jemals getrackt *war* — auch wenn sie heute
 *      entfernt ist.
 *
 * Punkt 4 kam dazu, nachdem er gefehlt hat. Vier interne Dokumente waren im
 * Repository, wurden spaeter entfernt, und die Pruefung meldete grün: sie sah
 * nur den heutigen Stand. Der Inhalt stand aber weiter in der Historie und
 * waere beim naechsten Push mitgegangen.
 *
 * Punkt 3 und 4 sind beide der leicht zu uebersehende Fall: was einmal in
 * einem Commit steht, ist im Repository, auch wenn heute keine Datei mehr
 * davon zeugt — und es laesst sich nicht zurueckholen, sobald jemand geklont
 * hat.
 *
 * Aufruf: php tools/check-publication-hygiene.php
 */

/**
 * Muster, die niemals getrackt sein duerfen, mit Begruendung.
 *
 * @var array<string, string>
 */
const FORBIDDEN = [
    '#^docs/superpowers/#' => 'Interne Spezifikationen und Pläne',
    '#^docs/internal/#' => 'Interne Arbeitsnotizen',
    '#^(CLAUDE|AGENTS|GEMINI)\.md$#' => 'Anleitung für KI-Werkzeuge',
    '#^\.claude/#' => 'Claude-Code-Konfiguration',
    '#^\.cursor#' => 'Cursor-Konfiguration',
    '#^\.aider#' => 'Aider-Konfiguration',
    '#^\.windsurfrules$#' => 'Windsurf-Konfiguration',
    '#^\.github/copilot-instructions\.md$#' => 'Copilot-Konfiguration',
    '#^\.(idea|vscode|fleet|zed)/#' => 'Editor-Konfiguration',
    '#\.iml$#' => 'Editor-Konfiguration',
    '#\.(pem|key|p12|pfx|jks)$#' => 'Schlüssel- oder Zertifikatsdatei',
    '#^id_(rsa|ed25519)#' => 'Privater SSH-Schlüssel',
    '#^\.env\.local#' => 'Lokale Zugangsdaten',
    '#^\.env\..*\.local$#' => 'Lokale Zugangsdaten',
];

/**
 * Adressen, die im Repository stehen duerfen.
 *
 * Die GitHub-Noreply-Adresse verknuepft Commits mit dem Konto, ohne die echte
 * Adresse preiszugeben. Beispieldomaenen sind laut RFC 2606 fuer genau diesen
 * Zweck reserviert.
 */
const ALLOWED_ADDRESS_PATTERNS = [
    '#@users\\.noreply\\.github\\.com$#i',
    '#^noreply@#i',
    '#@example\\.(org|com|net)$#i',
    // Kurzadressen aus den Tests des Email-Wertobjekts.
    '#^[a-z]@[a-z]\\.de$#i',
];

/**
 * Dateien, in denen fremde Adressen unvermeidbar sind.
 *
 * Sperrdateien halten die Angaben der Pakete, und darin stehen die Adressen
 * ihrer Autoren. Jedes Plugin bringt eine eigene mit — der Vergleich laeuft
 * deshalb ueber den Dateinamen und nicht ueber den ganzen Pfad.
 */
const CONTENT_SCAN_EXCEPTIONS = ['composer.lock'];

const ADDRESS_PATTERN = '#[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\\.[A-Za-z]{2,}#';

/**
 * Welche Historie zaehlt.
 *
 * Zweige, Markierungen und ihre Gegenstuecke beim Anbieter — also alles, was
 * beim Pushen mitgeht. Nicht --all: darunter fallen auch Refs, die Werkzeuge
 * fuer sich anlegen (Zwischenstaende von Review-Laeufen, refs/original aus
 * einer frueheren Bereinigung, der Stash). Die verlassen den Rechner nie, und
 * ein Pruefer, der wegen ihnen rot ist, wird abgeschaltet statt gelesen.
 */
const HISTORY_REFS = 'git log --branches --tags --remotes';

function isAllowedAddress(string $address): bool
{
    foreach (ALLOWED_ADDRESS_PATTERNS as $pattern) {
        if (1 === preg_match($pattern, $address)) {
            return true;
        }
    }

    return false;
}

/**
 * @param list<string> $tracked
 *
 * @return array<string, string>
 */
function addressesInFiles(array $tracked): array
{
    $found = [];

    foreach ($tracked as $file) {
        if (in_array(basename($file), CONTENT_SCAN_EXCEPTIONS, true) || !is_file($file)) {
            continue;
        }

        preg_match_all(ADDRESS_PATTERN, (string) file_get_contents($file), $matches);

        foreach ($matches[0] as $address) {
            if (!isAllowedAddress($address)) {
                $found[$address] = $file;
            }
        }
    }

    return $found;
}

/**
 * @return list<string>
 */
function addressesInHistory(): array
{
    $lines = [];
    exec(HISTORY_REFS.' --format=%ae%n%ce', $lines, $historyStatus);

    if (0 !== $historyStatus) {
        return [];
    }

    $found = [];

    foreach (array_unique($lines) as $address) {
        if ('' !== $address && !isAllowedAddress($address)) {
            $found[] = $address;
        }
    }

    sort($found);

    return $found;
}

/**
 * Interne Dateien, die irgendwann einmal in einem Commit standen.
 *
 * Gefragt wird nach allen Pfaden, die je in der Historie vorkamen — nicht nur
 * nach denen im heutigen Baum. Eine geloeschte Datei ist aus dem Baum
 * verschwunden, ihr Inhalt aber nicht: er haengt an dem Commit, der sie
 * hinzugefuegt hat, und wandert bei jedem Klon und jedem Push mit.
 *
 * @return array<string, string> Pfad => Grund
 */
function forbiddenInHistory(): array
{
    $paths = [];
    exec(HISTORY_REFS.' --pretty=format: --name-only --diff-filter=A', $paths, $status);

    if (0 !== $status) {
        return [];
    }

    $found = [];

    foreach (array_unique($paths) as $path) {
        if ('' === $path) {
            continue;
        }

        foreach (FORBIDDEN as $pattern => $reason) {
            if (1 === preg_match($pattern, $path)) {
                $found[$path] = $reason;

                break;
            }
        }
    }

    ksort($found);

    return $found;
}

exec('git ls-files', $tracked, $status);

if (0 !== $status) {
    fwrite(\STDERR, "git ls-files ist fehlgeschlagen. Läuft das hier in einem Git-Repository?\n");

    exit(1);
}

$violations = [];

foreach ($tracked as $file) {
    foreach (FORBIDDEN as $pattern => $reason) {
        if (1 === preg_match($pattern, $file)) {
            $violations[$file] = $reason;

            break;
        }
    }
}

$addressesInFiles = addressesInFiles($tracked);
$addressesInHistory = addressesInHistory();
$inHistory = forbiddenInHistory();

if ([] === $violations && [] === $addressesInFiles && [] === $addressesInHistory && [] === $inHistory) {
    printf(
        "Veröffentlichungshygiene: %d Dateien, nichts Internes (auch nicht in der Historie), keine persönlichen Adressen.\n",
        count($tracked),
    );

    exit(0);
}

if ([] !== $inHistory) {
    printf("%d interne Datei(en) stehen in der Git-Historie:\n\n", count($inHistory));

    foreach ($inHistory as $file => $reason) {
        printf("  %-56s %s\n", $file, $reason);
    }

    echo "\nSie sind heute vielleicht gelöscht — ihr Inhalt hängt aber weiter an dem\n";
    echo "Commit, der sie hinzugefügt hat, und wandert bei jedem Klon mit.\n";
    echo "Entfernen lässt sich das nur, indem die Historie neu geschrieben wird:\n\n";
    echo "  git filter-branch --index-filter 'git rm -r --cached --ignore-unmatch <pfad>' \\\n";
    echo "      --prune-empty <erster-ungepushter-commit>..HEAD\n\n";
    echo "Was schon gepusht ist, bleibt beim Anbieter liegen. Dann hilft nur, das\n";
    echo "Repository neu anzulegen — siehe docs/licensing/public-launch-checklist.md.\n\n";
}

foreach ($addressesInFiles as $address => $file) {
    printf("Persönliche Adresse in einer getrackten Datei: %s in %s\n", $address, $file);
}

foreach ($addressesInHistory as $address) {
    printf("Persönliche Adresse in der Git-Historie: %s\n", $address);
}

if ([] !== $addressesInFiles || [] !== $addressesInHistory) {
    echo "\nStattdessen die GitHub-Noreply-Adresse verwenden. Commit-Metadaten\n";
    echo "sind Teil des Repositorys, auch wenn die Adresse in keiner Datei steht.\n\n";
}

if ([] === $violations) {
    exit(1);
}

echo "\n";

printf("%d interne Datei(en) sind getrackt und würden veröffentlicht:\n\n", count($violations));

foreach ($violations as $file => $reason) {
    printf("  %-56s %s\n", $file, $reason);
}

echo "\nEntfernen mit:  git rm --cached <datei>\n";
echo "Die Datei bleibt dabei lokal erhalten, nur der Git-Index verliert sie.\n";
echo "Prüfen, dass ein passendes Muster in .gitignore steht.\n";

exit(1);
