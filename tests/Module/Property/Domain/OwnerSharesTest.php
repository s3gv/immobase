<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Tests\Module\Property\Domain;

use App\Module\Property\Domain\Holding;
use App\Module\Property\Domain\ManagementMode;
use App\Module\Property\Domain\ManagementModes;
use App\Module\Property\Domain\Mea;
use App\Module\Property\Domain\OwnerShares;
use App\Module\Property\Domain\Property;
use App\Module\Property\Domain\Unit;
use App\Module\Property\Domain\UnitOwner;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

/**
 * Geht die Verteilung auf — und wann?
 *
 * Seit Eigentum einen Zeitraum hat, ist „die Summe der Anteile" keine Zahl
 * mehr, sondern eine Zahl je Tag. Nach einem gewoehnlichen Verkauf stehen
 * zwei Zeilen mit dem vollen Anteil da; sie zusammenzuzaehlen ergaebe das
 * Doppelte und die Meldung „zu viel verteilt" — obwohl an keinem einzigen Tag
 * zu viel verteilt ist.
 */
final class OwnerSharesTest extends TestCase
{
    public function testASaleLeavesTheDistributionCompleteOnEveryDay(): void
    {
        $unit = self::soldOn('2026-07-01');

        foreach (['2026-01-01', '2026-06-30', '2026-07-01', '2026-12-31'] as $day) {
            self::assertTrue(
                OwnerShares::balanceOn($unit, new DateTimeImmutable($day))->isComplete(),
                'Am '.$day.' gehört die Einheit genau einer Partei',
            );
        }

        self::assertSame([], OwnerShares::periodsThatDoNotAddUp($unit));
    }

    /** Und die zeitlose Summe zeigt, warum sie als Bilanz nicht taugt. */
    public function testTheTimelessSumWouldSayTwiceAsMuch(): void
    {
        $unit = self::soldOn('2026-07-01');

        self::assertSame('500/1000', OwnerShares::everHeld($unit)->toString());
        self::assertSame('250/1000', OwnerShares::heldOn($unit, new DateTimeImmutable('2026-03-01'))->toString());
    }

    /**
     * Eine Luecke zwischen zwei Eigentuemern wird gemeldet.
     *
     * Der Verkaeufer hoert Ende Juni auf, die Kaeuferin faengt erst im August
     * an: im Juli gehoerte die Wohnung trotzdem jemandem, und abgerechnet
     * wird auch der Juli.
     */
    public function testAGapBetweenTwoOwnersIsReported(): void
    {
        $unit = self::aUnit();
        new UnitOwner($unit, 'party-a', Mea::of('250', 1000), Holding::of(null, new DateTimeImmutable('2026-06-30')));
        new UnitOwner($unit, 'party-b', Mea::of('250', 1000), Holding::of(new DateTimeImmutable('2026-08-01'), null));

        $open = OwnerShares::periodsThatDoNotAddUp($unit);

        self::assertCount(1, $open);
        self::assertSame('2026-07-01', $open[0]['from']->format('Y-m-d'));
        self::assertSame('2026-07-31', $open[0]['to']->format('Y-m-d'));
    }

    /** Vor dem ersten Eintrag ist keine Luecke, sondern keine Angabe. */
    public function testBeforeTheFirstEntryNothingIsReported(): void
    {
        $unit = self::aUnit();
        new UnitOwner($unit, 'party-a', Mea::of('250', 1000), Holding::of(new DateTimeImmutable('2026-03-01'), null));

        self::assertSame([], OwnerShares::periodsThatDoNotAddUp($unit));
    }

    /** Eine halbe Verteilung bleibt eine halbe — auch mit Zeitraum. */
    public function testAnIncompletePeriodIsStillReported(): void
    {
        $unit = self::aUnit();
        new UnitOwner($unit, 'party-a', Mea::of('100', 1000), Holding::always());

        $open = OwnerShares::periodsThatDoNotAddUp($unit);

        self::assertCount(1, $open);
        self::assertTrue($open[0]['balance']->isShort());
    }

    /** Verkauft zum Stichtag: der Vortag gehoert noch dem Verkaeufer. */
    private static function soldOn(string $day): Unit
    {
        $unit = self::aUnit();
        $sold = new DateTimeImmutable($day);

        new UnitOwner($unit, 'party-a', Mea::of('250', 1000), Holding::of(null, $sold->modify('-1 day')));
        new UnitOwner($unit, 'party-b', Mea::of('250', 1000), Holding::of($sold, null));

        return $unit;
    }

    private static function aUnit(): Unit
    {
        $property = new Property(29101, 'Anteilsprüfung', ManagementModes::of([ManagementMode::Weg]));
        $unit = new Unit($property, 'WE 1');
        $unit->holdShare(Mea::of('250', 1000));

        return $unit;
    }
}
