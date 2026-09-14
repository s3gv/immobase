<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Audit\Application;

use App\Module\Audit\Domain\AuditRepository;
use Symfony\Component\Clock\ClockInterface;

/**
 * Was aelter als achtundvierzig Stunden ist, faellt weg.
 *
 * **Eine Frist, die wirklich laeuft.** Ein Protokoll ohne sie waechst still,
 * bis es die Datenbank fuellt — und niemand entscheidet je, wann es genug
 * ist. Zwei Tage sind die Spanne, in der jemand merkt, dass etwas schieflief,
 * und nachsieht; was laenger bleiben soll, wird vorher ausgedruckt.
 *
 * Geraeumt wird in derselben Schleife wie die Anhaenge des Portals: kein
 * zweiter Behaelter, kein zweiter Zeitplan.
 */
final readonly class SweepTheTrail
{
    public const int HOURS = 48;

    public function __construct(
        private AuditRepository $entries,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @return int wie viele Zeilen gegangen sind
     */
    public function __invoke(): int
    {
        return $this->entries->forgetBefore($this->clock->now()->modify('-'.self::HOURS.' hours'));
    }
}
