<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Tests\Module\Property\Application;

use App\Module\Property\Application\AssignOwners;
use App\Module\Property\Domain\ManagementMode;
use App\Module\Property\Domain\ManagementModes;
use App\Module\Property\Domain\Mea;
use App\Module\Property\Domain\Property;
use App\Module\Property\Domain\Unit;
use App\Module\Property\Domain\UnitOwner;
use App\Module\Property\Domain\UnknownOwner;
use App\Tests\Module\Property\Fixture\InMemoryUnitRepository;
use App\Tests\Module\Property\Fixture\KnownParties;
use PHPUnit\Framework\TestCase;

/**
 * Wem eine Einheit gehoert — und zu welchem Anteil.
 */
final class AssignOwnersTest extends TestCase
{
    /**
     * Bei einem einzigen Eigentuemer gehoert ihm die ganze Einheit. Ihn den
     * Anteil abtippen zu lassen, den die Einheit ohnehin traegt, ist eine
     * Gelegenheit fuer einen Zahlendreher und sonst nichts.
     */
    public function testASingleOwnerGetsTheWholeShareWithoutTyping(): void
    {
        $unit = self::unit();

        self::assign()->to($unit, Mea::of('50', 1000), self::rows(['party-a' => '']));

        self::assertCount(1, $unit->owners());
        self::assertSame(
            ['50/1000'],
            array_map(static fn (UnitOwner $o): string => $o->mea()->toString(), $unit->owners()),
        );
        self::assertTrue($unit->everHeldByOwners()->equals($unit->mea()));
    }

    public function testSeveralOwnersSplitItThemselves(): void
    {
        $unit = self::unit();

        self::assign()->to($unit, Mea::of('50', 1000), self::rows(['party-a' => '30', 'party-b' => '20']));

        self::assertSame(
            ['30/1000', '20/1000'],
            array_map(static fn (UnitOwner $o): string => $o->mea()->toString(), $unit->owners()),
        );
        self::assertTrue($unit->everHeldByOwners()->equals($unit->mea()));
    }

    /**
     * Eine Aufteilung, die nicht aufgeht, wird gespeichert und angezeigt —
     * nicht abgelehnt. Wer sie halb erfasst hat, soll morgen weitermachen
     * koennen.
     */
    public function testAnIncompleteSplitIsKeptAndVisible(): void
    {
        $unit = self::unit();

        self::assign()->to($unit, Mea::of('50', 1000), self::rows(['party-a' => '30', 'party-b' => '10']));

        self::assertSame('40/1000', $unit->everHeldByOwners()->toString());
        self::assertTrue($unit->everHeldByOwners()->isLessThan($unit->mea()));
    }

    /** Die Zuordnung wird immer als Ganzes gesetzt. */
    public function testAssigningReplacesTheWholeList(): void
    {
        $unit = self::unit();
        self::assign()->to($unit, Mea::of('50', 1000), self::rows(['party-a' => '30', 'party-b' => '20']));

        self::assign()->to($unit, Mea::of('50', 1000), self::rows(['party-c' => '50']));

        self::assertSame(
            ['party-c'],
            array_map(static fn (UnitOwner $o): string => $o->partyId(), $unit->owners()),
        );
    }

    /**
     * Mehrere Eigentuemer ohne Angabe bekommen nichts: die Aufteilung steht
     * nirgends, und sie zu erfinden waere schlimmer als sie offen zu lassen.
     */
    public function testSeveralOwnersWithoutFiguresGetNothing(): void
    {
        $unit = self::unit();

        self::assign()->to($unit, Mea::of('50', 1000), self::rows(['party-a' => '', 'party-b' => '']));

        self::assertTrue($unit->everHeldByOwners()->isZero());
    }

    /**
     * Eine Kennung, zu der es keinen Stammdatensatz gibt, wird abgelehnt —
     * und zwar bevor irgendetwas anderes uebernommen wird.
     *
     * Die Datenbank haette es auch abgelehnt (siehe OwnerIntegrityTest), aber
     * erst beim Speichern und mit einem Fehler, den niemand lesen will. Hier
     * faellt es frueher auf — und vor der ersten Aenderung.
     */
    public function testAnUnknownContactIsRefused(): void
    {
        $unit = self::unit();

        try {
            self::assign()->to($unit, Mea::of('50', 1000), self::rows(['party-a' => '20', 'erfunden' => '30']));
            self::fail('Die erfundene Kennung wurde als Eigentuemer uebernommen.');
        } catch (UnknownOwner) {
            self::assertSame([], $unit->owners());
            self::assertTrue($unit->mea()->isZero(), 'Der Anteil der Einheit wurde trotz Ablehnung gesetzt.');
        }
    }

    private static function assign(): AssignOwners
    {
        return new AssignOwners(new InMemoryUnitRepository(), new KnownParties('party-a', 'party-b', 'party-c'));
    }

    private static function unit(): Unit
    {
        $property = new Property(20001, 'Musterweg 1', ManagementModes::of([ManagementMode::Weg]));

        return new Unit($property, 'WE 1');
    }

    /**
     * Die Kurzform des Tests in die Form des Formulars.
     *
     * Dort steht je Zeile der Kontakt, sein Anteil und sein Zeitraum — und
     * der Schluessel ist die Zeile und nicht der Kontakt. Wo der Zeitraum
     * nichts zur Sache tut, bleibt er offen: „schon immer und noch".
     *
     * Steht die Zeile schon an der Einheit, kommt sie mit **ihrer** Kennung
     * zurueck — so wie das Formular sie zeichnet. Nur so erkennt die
     * Zuordnung sie wieder; mit einem frischen Schluessel entstuende eine
     * zweite Zeile fuer denselben Zeitraum.
     *
     * @param array<string, string> $shares Kennung des Kontakts auf Zaehler
     *
     * @return array<string, array{party: string, mea: string, von: string, bis: string}>
     */
    private static function rows(array $shares, ?Unit $unit = null): array
    {
        $known = [];

        foreach (null === $unit ? [] : $unit->owners() as $owner) {
            $known[$owner->partyId()] = $owner->id();
        }

        $rows = [];
        $at = 0;

        foreach ($shares as $partyId => $mea) {
            ++$at;
            $key = $known[$partyId] ?? 'neu-'.$at;
            $rows[$key] = ['party' => $partyId, 'mea' => $mea, 'von' => '', 'bis' => ''];
        }

        return $rows;
    }
}
