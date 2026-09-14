<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Tests\Module\Property\Domain;

use App\Module\Property\Domain\ManagementMode;
use App\Module\Property\Domain\ManagementModes;
use App\Module\Property\Domain\Mea;
use App\Module\Property\Domain\MeaAlreadyDistributed;
use App\Module\Property\Domain\MeaDenominator;
use App\Module\Property\Domain\Property;
use App\Module\Property\Domain\PropertyStatus;
use App\Module\Property\Domain\Unit;
use App\Module\Property\Domain\UnitUsage;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * Das Objekt und seine Einheiten.
 */
final class PropertyTest extends TestCase
{
    public function testStartsAsADraftWithItsNumber(): void
    {
        $property = self::property();

        self::assertSame(20001, $property->number());
        self::assertSame(PropertyStatus::Draft, $property->status());
        self::assertTrue($property->status()->isDraft());
    }

    /**
     * Der letzte Schritt macht aus dem Entwurf ein Objekt. Bis dahin steht es
     * in der Liste und laesst sich fortsetzen.
     */
    public function testTheLastStepMakesItReal(): void
    {
        $property = self::property();
        $property->managedAs($property->management()->activated());

        self::assertSame(PropertyStatus::Active, $property->status());
    }

    public function testNeedsAtLeastOneManagementMode(): void
    {
        $this->expectException(InvalidArgumentException::class);

        ManagementModes::of([]);
    }

    public function testNeedsAName(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new Property(20001, '   ', ManagementModes::of([ManagementMode::Weg]));
    }

    /**
     * Reine Mietverwaltung kennt keine Miteigentumsanteile — dort gibt es
     * nichts zu verteilen und deshalb auch nichts zu fragen.
     */
    public function testOnlyOwnersAssociationsNeedShares(): void
    {
        self::assertTrue(ManagementModes::of([ManagementMode::Weg])->needMea());
        self::assertTrue(ManagementModes::of([ManagementMode::Sev])->needMea());
        self::assertFalse(ManagementModes::of([ManagementMode::Rental])->needMea());
        self::assertTrue(
            ManagementModes::of([ManagementMode::Rental, ManagementMode::Weg])->needMea(),
            'Eines von beiden genügt',
        );
    }

    /**
     * Die Nummer laeuft je Objekt und wird nach dem Loeschen nicht neu
     * vergeben: sie steht in der Seitenleiste, und eine Nummer, die sich
     * aendert, ist keine.
     */
    public function testUnitNumbersRunPerPropertyAndAreNotReused(): void
    {
        $property = self::property();

        $first = new Unit($property, 'WE 1');
        $second = new Unit($property, 'WE 2');
        $third = new Unit($property, 'WE 3');

        self::assertSame([1, 2, 3], [$first->number(), $second->number(), $third->number()]);

        $property->remove($second);

        self::assertSame(4, (new Unit($property, 'WE 4'))->number(), 'Die 2 bleibt vergeben');
    }

    public function testCountsItsUnits(): void
    {
        $property = self::property();
        new Unit($property, 'WE 1');
        new Unit($property, 'WE 2');

        self::assertCount(2, $property->units());
    }

    /**
     * Ein Wechsel von 1000 auf 10000 wuerde jeden erfassten Anteil um den
     * Faktor zehn verschieben. Stillschweigend umzurechnen waere schlimmer,
     * als es abzulehnen.
     */
    public function testTheDenominatorIsFixedOnceSharesAreDistributed(): void
    {
        $property = self::property();
        $unit = new Unit($property, 'WE 1');
        $unit->holdShare(Mea::of('500', 1000));

        $this->expectException(MeaAlreadyDistributed::class);
        $property->scaleMeaTo(MeaDenominator::TenThousand);
    }

    public function testTheDenominatorStaysChangeableWhileNothingIsDistributed(): void
    {
        $property = self::property();
        new Unit($property, 'WE 1');

        $property->scaleMeaTo(MeaDenominator::TenThousand);

        self::assertSame(MeaDenominator::TenThousand, $property->shares()->denominator());
    }

    /**
     * Der Anteil haengt an der Einheit; die Eigentuemer teilen ihn. Fehlt
     * einer, halten sie zusammen weniger, als die Einheit hat — genau das
     * soll die Anzeige sagen.
     */
    public function testWhatTheOwnersHoldIsSeparateFromWhatTheUnitHolds(): void
    {
        $property = self::property();
        $unit = new Unit($property, 'WE 1');
        $unit->holdShare(Mea::of('50', 1000));

        self::assertTrue($unit->everHeldByOwners()->isZero(), 'Noch kein Eigentümer eingetragen');
        self::assertSame('50/1000', $unit->mea()->toString());
    }

    /** Die Nutzungsart entscheidet, wie das Flaechenfeld heisst. */
    public function testTheAreaLabelFollowsTheUse(): void
    {
        self::assertSame('property.unit.field.living_area', UnitUsage::Residential->areaLabelKey());
        self::assertSame('property.unit.field.usable_area', UnitUsage::Parking->areaLabelKey());
    }

    private static function property(): Property
    {
        return new Property(20001, 'Musterweg 1', ManagementModes::of([ManagementMode::Weg]));
    }
}
