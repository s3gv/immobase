<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Property\Contract\Event;

use App\Shared\Event\DomainEvent;
use DateTimeImmutable;

/**
 * Die Verwaltung dieses Objekts endet — mit allem, was daran haengt.
 *
 * Das Objektmodul beendet die Mietverhaeltnisse nicht selbst: die Miete kennt
 * die Objekte, ein Aufruf zurueck waere der Zyklus, den `docs/conventions.md`
 * ausschliesst. Es sagt nur, was passiert ist; wer darauf hoert, entscheidet
 * selbst, was das fuer seine Daten heisst.
 *
 * Die Einheiten stehen im Ereignis selbst und loesen kein eigenes
 * `UnitClosed` aus. Sonst haenge die Vollstaendigkeit einer Abwicklung an der
 * Reihenfolge zweier Zuhoerer.
 */
final readonly class PropertyClosed implements DomainEvent
{
    /**
     * @param list<string> $unitIds
     */
    public function __construct(
        public string $propertyId,
        public array $unitIds,
        public DateTimeImmutable $effectiveOn,
    ) {
    }
}
