<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Property\Domain;

use App\Shared\Time\Segments;
use DateTimeImmutable;

/**
 * Geht die Verteilung einer Einheit auf — und wann?
 *
 * Seit Eigentum einen Zeitraum hat, ist „die Summe der Anteile" keine Zahl
 * mehr, sondern eine Zahl **je Tag**. Nach einem gewoehnlichen Verkauf stehen
 * zwei Zeilen mit dem vollen Anteil da: der Verkaeufer bis zum 30. Juni, die
 * Kaeuferin ab dem 1. Juli. Beide zusammenzuzaehlen ergaebe das Doppelte und
 * die Meldung „zu viel verteilt" — obwohl an keinem einzigen Tag zu viel
 * verteilt ist.
 *
 * Gefragt wird deshalb immer zu einem Stichtag. Und weil eine Verteilung, die
 * heute aufgeht, im Mai eine Luecke gehabt haben kann, gibt es daneben die
 * Frage nach **allen** Abschnitten.
 */
final class OwnerShares
{
    private function __construct()
    {
    }

    /**
     * Was **je** eingetragen wurde, ohne Ruecksicht auf den Zeitraum.
     *
     * Nur fuer die Frage, ob ueberhaupt schon etwas verteilt ist — daran
     * haengt der Nennerwechsel. Als Bilanz einer Einheit waere diese Zahl
     * falsch: nach einem Verkauf stehen zwei volle Anteile da, und an keinem
     * Tag sind es zwei.
     */
    public static function everHeld(Unit $unit): Mea
    {
        return Mea::sum($unit->owners(), $unit->property()->shares()->denominator()->value);
    }

    /** Was die Eigentuemer an diesem Tag zusammen halten. */
    public static function heldOn(Unit $unit, DateTimeImmutable $day): Mea
    {
        return Mea::sum(self::owningOn($unit, $day), $unit->property()->shares()->denominator()->value);
    }

    public static function balanceOn(Unit $unit, DateTimeImmutable $day): MeaBalance
    {
        return MeaBalance::of(self::heldOn($unit, $day), $unit->mea());
    }

    /**
     * Die Abschnitte, in denen die Verteilung **nicht** aufgeht.
     *
     * Vor dem ersten und nach dem letzten Eintrag wird nichts gemeldet: eine
     * Einheit, deren Eigentuemer erst ab Maerz erfasst ist, hat davor keine
     * Luecke, sondern keine Angabe. **Dazwischen** dagegen schon — wenn der
     * Verkaeufer Ende Juni aufhoert und die Kaeuferin erst im August anfaengt,
     * gehoerte die Wohnung im Juli trotzdem jemandem.
     *
     * @return list<array{from: DateTimeImmutable, to: DateTimeImmutable, balance: MeaBalance}>
     */
    public static function periodsThatDoNotAddUp(Unit $unit): array
    {
        $sections = self::sections($unit);
        $owned = array_keys(array_filter(
            $sections,
            static fn (array $section): bool => [] !== self::owningOn($unit, $section['from']),
        ));

        if ([] === $owned) {
            return [];
        }

        $found = [];

        foreach (\array_slice($sections, min($owned), max($owned) - min($owned) + 1) as $section) {
            $balance = self::balanceOn($unit, $section['from']);

            if (!$balance->isComplete()) {
                $found[] = [...$section, 'balance' => $balance];
            }
        }

        return $found;
    }

    /**
     * Der Zeitstrahl der Einheit, geschnitten an jedem Wechsel.
     *
     * Die Raender kommen aus den Eintraegen selbst: der frueheste Anfang und
     * das spaeteste Ende. Offene Enden werden dabei zu dem Tag, an dem etwas
     * anderes beginnt — was davor und danach gilt, aendert sich nicht mehr.
     *
     * @return list<array{from: DateTimeImmutable, to: DateTimeImmutable}>
     */
    private static function sections(Unit $unit): array
    {
        $days = [];

        foreach ($unit->owners() as $owner) {
            $days[] = $owner->holding()->from() ?? new DateTimeImmutable('today');
            $days[] = $owner->holding()->to() ?? new DateTimeImmutable('today');
        }

        if ([] === $days) {
            return [];
        }

        return Segments::cut(self::holdings($unit), min($days), max($days));
    }

    /**
     * @return list<array{from: DateTimeImmutable|null, to: DateTimeImmutable|null}>
     */
    private static function holdings(Unit $unit): array
    {
        return array_map(
            static fn (UnitOwner $owner): array => [
                'from' => $owner->holding()->from(),
                'to' => $owner->holding()->to(),
            ],
            $unit->owners(),
        );
    }

    /**
     * @return list<UnitOwner>
     */
    private static function owningOn(Unit $unit, DateTimeImmutable $day): array
    {
        return array_values(array_filter(
            $unit->owners(),
            static fn (UnitOwner $owner): bool => $owner->holding()->covers($day),
        ));
    }
}
