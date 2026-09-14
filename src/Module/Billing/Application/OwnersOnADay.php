<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Billing\Application;

use App\Module\Property\Contract\OwnerShare;
use App\Module\Property\Contract\OwnershipSpan;
use App\Module\Property\Contract\UnitBrief;
use App\Module\Property\Contract\UnitOwnership;
use DateTimeImmutable;

/**
 * Wem die Einheiten an einem bestimmten Tag gehoerten.
 *
 * Je Einheit einer, und das ist der Unterschied zur Abrechnung: dort entsteht
 * je Eigentuemerabschnitt ein Schreiben, weil abgerechnet wird, was jemand
 * getragen hat. Hier zaehlt ein einziger Tag — und dann gibt es je Einheit
 * genau einen Empfaenger, auch wenn im Jahr verkauft wurde.
 *
 * Zwei Schreiben fragen so: der **Wirtschaftsplan** fragt nach dem ersten Tag
 * des Planjahres — wer unterjaehrig kauft, uebernimmt den laufenden Vorschuss
 * und bekommt keinen halben Plan. Der **Vermoegensbericht** fragt nach dem
 * Stichtag, ueber den er Auskunft gibt.
 */
final readonly class OwnersOnADay
{
    public function __construct(
        private UnitOwnership $ownership,
        private Addressed $addressed,
    ) {
    }

    /**
     * @param list<UnitBrief> $units
     *
     * @return array<string, array{label: string, address: string}> Kennung der Einheit auf ihren Empfaenger
     */
    public function on(array $units, DateTimeImmutable $day): array
    {
        $ids = array_map(static fn (UnitBrief $unit): string => $unit->id, $units);
        $held = $this->ownership->inPeriod($ids, $day, $day);
        $found = [];

        foreach ($units as $unit) {
            $owners = $this->ownersOf($held[$unit->id] ?? []);

            if (null !== $owners) {
                $found[$unit->id] = $owners;
            }
        }

        return $found;
    }

    /**
     * @param list<OwnershipSpan> $held ein einziger Tag ergibt hoechstens einen Abschnitt
     *
     * @return array{label: string, address: string}|null
     */
    private function ownersOf(array $held): ?array
    {
        $span = $held[0] ?? null;

        if (null === $span) {
            return null;
        }

        return $this->addressed->of(array_map(
            static fn (OwnerShare $share): string => $share->partyId,
            $span->owners,
        ));
    }
}
