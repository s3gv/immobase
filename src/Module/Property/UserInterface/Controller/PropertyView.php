<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Property\UserInterface\Controller;

use App\Module\Party\Contract\PartyDirectory;
use App\Module\Property\Application\CountUnitLinks;
use App\Module\Property\Domain\Mea;
use App\Module\Property\Domain\MeaBalance;
use App\Module\Property\Domain\OwnerShares;
use App\Module\Property\Domain\Property;
use App\Module\Property\Domain\Unit;
use App\Module\Property\Domain\UnitOwner;
use DateTimeImmutable;

/**
 * Einheiten und Anteile, fertig zum Zeichnen.
 *
 * Die Eigentuemer werden fuer alle Einheiten in einem Zug nachgeschlagen —
 * eine Abfrage je Zeile waere genau das Muster, das Listen langsam macht.
 * Nachgeschlagen wird ueber PartyDirectory: das Objektmodul kennt vom
 * Stammdatenmodul nur seinen Contract.
 *
 * Dasselbe gilt fuer die Verweise auf die Einheiten. Sie entscheiden, ob eine
 * Einheit noch geloescht werden darf — und die Antwort gehoert in dieselbe
 * Mengenabfrage.
 */
final readonly class PropertyView
{
    public function __construct(
        private PartyDirectory $parties,
        private CountUnitLinks $links,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function data(Property $property): array
    {
        $units = $property->units();

        $ids = array_map(static fn (Unit $unit): string => $unit->id(), $units);

        return [
            'units' => $this->describe($units, $this->briefsFor($units), $this->links->forUnits($ids)),
            'balance' => self::balanceOf($property),
        ];
    }

    /**
     * Was alle Einheiten zusammen halten, gegen den ganzen Nenner.
     */
    public static function balanceOf(Property $property): MeaBalance
    {
        $denominator = $property->shares()->denominator()->value;
        $sum = Mea::none($denominator);

        foreach ($property->units() as $unit) {
            $sum = $sum->plus($unit->mea());
        }

        return MeaBalance::of($sum, Mea::whole($denominator));
    }

    /**
     * @param list<Unit> $units
     *
     * @return array<string, \App\Module\Party\Contract\PartyBrief>
     */
    private function briefsFor(array $units): array
    {
        $ids = [];

        foreach ($units as $unit) {
            foreach ($unit->owners() as $owner) {
                $ids[$owner->partyId()] = true;
            }
        }

        return $this->parties->byIds(array_keys($ids));
    }

    /**
     * @param list<Unit>                                                  $units
     * @param array<string, \App\Module\Party\Contract\PartyBrief>        $briefs
     * @param array<string, list<\App\Module\Property\Contract\UnitLink>> $links
     *
     * @return list<array<string, mixed>>
     */
    private function describe(array $units, array $briefs, array $links): array
    {
        return array_map(static fn (Unit $unit): array => [
            'unit' => $unit,
            'owners' => array_map(static fn (UnitOwner $owner): array => [
                'brief' => $briefs[$owner->partyId()] ?? null,
                'partyId' => $owner->partyId(),
                'mea' => $owner->mea(),
            ], $unit->owners()),
            'balance' => OwnerShares::balanceOn($unit, new DateTimeImmutable('today')),
            'links' => $links[$unit->id()] ?? [],
        ], $units);
    }
}
