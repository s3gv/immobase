<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Tenancy\Contract;

use DateTimeImmutable;

/**
 * Ein Mietverhaeltnis, beschnitten auf einen Zeitraum.
 *
 * Was die Abrechnung braucht und nur das: wer, wie lange, mit welcher
 * Vorauszahlung und mit wie vielen Menschen. `from` und `to` sind bereits der
 * Schnitt aus Mietzeit und Abrechnungszeitraum — ein Mietverhaeltnis, das im
 * Mai beginnt, kommt hier mit dem 1. Mai an.
 *
 * Das Beschneiden geschieht hier und nicht beim Aufrufer, weil sonst jeder
 * Aufrufer es selbst tun muesste — und der zweite es anders taete.
 */
final readonly class TenancySpan
{
    /**
     * @param list<string>      $tenantPartyIds
     * @param list<AdvanceStep> $advances
     * @param list<PersonStep>  $persons
     */
    public function __construct(
        public string $tenancyId,
        public int $number,
        public string $unitId,
        public DateTimeImmutable $from,
        public DateTimeImmutable $to,
        public array $tenantPartyIds,
        public array $advances,
        public array $persons,
        /** Mit Umsatzsteuer vermietet (§ 9 UStG)? Dann wird netto abgerechnet. */
        public bool $vatCharged = false,
        /** Der Satz in Basispunkten: 19 % sind 1900. */
        public int $vatRateBps = 0,
    ) {
    }

    /** Die Tage, die dieser Abschnitt umfasst — beide Enden zaehlen mit. */
    public function days(): int
    {
        return (int) $this->from->diff($this->to)->days + 1;
    }
}
