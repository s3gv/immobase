<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Tenancy\Contract;

use App\Shared\Money\Money;
use DateTimeImmutable;

/**
 * Eine Stufe der Mietstaffel, so viel wie ein fremdes Modul davon braucht.
 *
 * Ab wann sie gilt und was sie kostet. Kein Ende: die naechste Stufe ist das
 * Ende der vorigen, und zwei Angaben ueber dasselbe Datum laufen
 * auseinander.
 *
 * Vier Positionen, wie die Miete selbst sie kennt — auf einer Rechnung
 * stehen sie einzeln, weil der Mieter sie einzeln wiederfinden muss.
 */
final readonly class RentPeriod
{
    public function __construct(
        public DateTimeImmutable $from,
        public Money $base,
        public Money $operatingCosts,
        public Money $heating,
        public Money $parking,
    ) {
    }

    public function total(): Money
    {
        return $this->base->plus($this->operatingCosts)->plus($this->heating)->plus($this->parking);
    }
}
