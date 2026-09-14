<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Tests\Module\Plugin\Reporting\Fixture;

use DateTimeImmutable;
use Reporting\MirrorStorage;

// Das Plugin teilt keinen Autoloader mit dem Core; seine Klassen werden
// einzeln geladen, auch hier.
require_once \dirname(__DIR__, 5).'/plugins/reporting/src/MirrorStorage.php';

/**
 * Ein Spiegelspeicher, bei dem man das Warten an der Sperre sieht.
 *
 * Wer die Sperre will, waehrend sie gehalten wird, kommt in eine Schlange
 * und laeuft, sobald die Arbeit davor fertig ist — so wie ein zweiter
 * Prozess an `pg_advisory_lock` wartet, nur ohne zweiten Prozess. Seine
 * Antwort kommt dabei erst spaeter; sofort zurueck gibt es nur `false`.
 */
final class WaitingStorage implements MirrorStorage
{
    /** @var array<string, list<array<string, scalar|null>>> */
    public array $tables = [];

    public ?DateTimeImmutable $lastSync = null;

    private bool $locked = false;

    /** @var list<callable(): bool> */
    private array $waiting = [];

    public function exclusively(callable $work): bool
    {
        if ($this->locked) {
            $this->waiting[] = $work;

            return false;
        }

        $this->locked = true;

        try {
            $result = $work();
        } finally {
            $this->locked = false;
        }

        while ([] !== $this->waiting) {
            $this->exclusively(array_shift($this->waiting));
        }

        return $result;
    }

    public function lastSync(string $name): ?DateTimeImmutable
    {
        return $this->lastSync;
    }

    public function replaceAll(array $tables, DateTimeImmutable $at): void
    {
        $this->tables = $tables;
        $this->lastSync = $at;
    }
}
