<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Plugin\Infrastructure;

use RuntimeException;

/**
 * Die Abhaengigkeiten eines Plugins nachinstallieren — ohne ihm dabei etwas zu geben.
 *
 * Im Image passiert das beim Bauen. In der Entwicklung liegt der Quellcode
 * eingebunden und ohne `vendor/` da; dann holt der Aufseher es einmal nach.
 *
 * **Composer fuehrt Code des Plugins aus**, wenn man es laesst: Scripts aus
 * seiner composer.json und Composer-Plugins aus seinen Abhaengigkeiten. Liefe
 * das mit der Umgebung des Cores, kaeme ein Plugin beim ersten Start an
 * DATABASE_URL und APP_SECRET — vorbei an der Prozessgrenze, die genau das
 * verhindern soll. Installiert wird deshalb ohne Scripts, ohne
 * Composer-Plugins und mit derselben kargen Umgebung wie der Plugin-Prozess.
 *
 * **Scheitert es, wird nicht gestartet.** Ein Server ohne Autoloader stuerbe
 * sofort, und der naechste Versuch saehe genauso aus — ohne dass irgendwo
 * stuende, warum. Die Ausgabe von Composer landet deshalb in einem
 * Protokoll, und das Wesentliche daraus steht in der Fehlermeldung.
 */
final readonly class PluginDependencies
{
    /** So viele Zeilen der Ausgabe stehen in der Meldung — der Rest im Protokoll. */
    private const int TAIL_LINES = 5;

    public function __construct(
        private string $stateDirectory,
        private string $composer = 'composer',
    ) {
    }

    public function installIn(string $directory): void
    {
        if (!is_file($directory.'/composer.json') || is_file($directory.'/vendor/autoload.php')) {
            return;
        }

        $name = basename($directory);
        $log = $this->logFile($name);
        $exitCode = $this->run($directory, $log);

        if (0 !== $exitCode || !is_file($directory.'/vendor/autoload.php')) {
            throw new RuntimeException(\sprintf('Die Abhängigkeiten von „%s" ließen sich nicht installieren (Composer endete mit %d%s): %s — Protokoll: %s', $name, $exitCode, 0 === $exitCode ? ', aber ohne vendor/autoload.php' : '', self::summaryOf($log), $log));
        }
    }

    /**
     * @return list<string>
     */
    public function command(string $directory): array
    {
        return [
            $this->composer, 'install',
            '--no-dev', '--no-interaction', '--prefer-dist', '--optimize-autoloader',
            '--no-scripts', '--no-plugins',
            '--working-dir='.$directory,
        ];
    }

    /**
     * Die Umgebung des Plugin-Prozesses, dazu ein eigenes Composer-Verzeichnis.
     *
     * Ohne `COMPOSER_HOME` suchte Composer ein Heimatverzeichnis, das es in
     * der kargen Umgebung nicht gibt, und bricht ab.
     *
     * @return array<string, string>
     */
    public function environment(): array
    {
        return ProcessEnvironment::only(['COMPOSER_HOME' => $this->stateDirectory.'/composer']);
    }

    /** Der Exit-Code — oder -1, wenn Composer gar nicht erst anlief. */
    private function run(string $directory, string $log): int
    {
        $handle = proc_open(
            $this->command($directory),
            [0 => ['file', '/dev/null', 'r'], 1 => ['file', $log, 'w'], 2 => ['file', $log, 'a']],
            $pipes,
            $directory,
            $this->environment(),
        );

        return \is_resource($handle) ? proc_close($handle) : -1;
    }

    private function logFile(string $name): string
    {
        if (!is_dir($this->stateDirectory) && !mkdir($this->stateDirectory, 0o775, true) && !is_dir($this->stateDirectory)) {
            throw new RuntimeException('Das Verzeichnis für Plugin-Prozesse ließ sich nicht anlegen.');
        }

        return $this->stateDirectory.'/'.$name.'.composer.log';
    }

    /**
     * Die Zeilen, die sagen, was los ist.
     *
     * Kann Composer die Abhaengigkeiten nicht aufloesen, steht der Grund
     * unter „Problem 1" — und danach folgen allgemeine Ratschlaege, die in
     * jeder solchen Ausgabe gleich lauten. Dann also dieser Block, sonst das
     * Ende der Ausgabe.
     */
    private static function summaryOf(string $log): string
    {
        $lines = is_file($log) ? file($log, \FILE_IGNORE_NEW_LINES) : false;
        $lines = array_map('trim', false === $lines ? [] : $lines);
        $problem = self::problemBlock($lines);
        $picked = [] !== $problem ? $problem : \array_slice(array_values(array_filter($lines, static fn (string $line): bool => '' !== $line)), -self::TAIL_LINES);

        return [] === $picked ? 'keine Ausgabe' : implode(' / ', $picked);
    }

    /**
     * @param list<string> $lines
     *
     * @return list<string>
     */
    private static function problemBlock(array $lines): array
    {
        $start = null;

        foreach ($lines as $index => $line) {
            if (1 === preg_match('/^Problem \d+$/D', $line)) {
                $start = $index;

                break;
            }
        }

        $block = [];

        for ($index = $start ?? \count($lines); $index < \count($lines) && '' !== $lines[$index]; ++$index) {
            $block[] = $lines[$index];
        }

        return $block;
    }
}
