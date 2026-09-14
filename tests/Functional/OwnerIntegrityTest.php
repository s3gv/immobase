<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Module\Party\Domain\Addresses;
use App\Module\Party\Domain\AddressKind;
use App\Module\Party\Domain\ContactDetails;
use App\Module\Party\Domain\Party;
use App\Module\Party\Domain\PartyKind;
use App\Module\Party\Domain\PartyRepository;
use App\Module\Party\Domain\PartyRole;
use App\Module\Party\Domain\PartyRoles;
use App\Module\Party\Domain\PostalAddress;
use App\Module\Property\Domain\ManagementMode;
use App\Module\Property\Domain\ManagementModes;
use App\Module\Property\Domain\Mea;
use App\Module\Property\Domain\Property;
use App\Module\Property\Domain\PropertyRepository;
use App\Module\Property\Domain\Unit;
use App\Module\Property\Domain\UnitOwner;
use App\Shared\Contact\Email;
use Doctrine\DBAL\Exception\ForeignKeyConstraintViolationException;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Eine Eigentuemerzeile zeigt auf einen Stammdatensatz, den es gibt.
 *
 * Die Anwendung schlaegt jede Kennung nach, bevor sie sie uebernimmt, und der
 * Loeschpfad fragt vorher, ob noch etwas daran haengt. Beides sind Pruefungen
 * auf einer Verbindung, und zwischen Pruefen und Handeln passt eine zweite
 * Anfrage. Was dann entstuende, waere eine Zeile, die auf nichts zeigt — nicht
 * anzeigbar, nicht zuzuordnen, und nirgends als Fehler sichtbar.
 *
 * Der Fremdschluessel schliesst genau dieses Fenster. Er koppelt keine
 * PHP-Module — das Objektmodul kennt die Party-Entity weiterhin nicht — und
 * gilt auch fuer alles, was ohne die Anwendung auf die Datenbank zugreift.
 *
 * Beide Faelle laufen hier ueber zwei Verbindungen: auf einer allein sieht
 * jede Anweisung, was die vorige getan hat, und das Wettrennen gaebe es nicht.
 */
final class OwnerIntegrityTest extends KernelTestCase
{
    use UsesASecondConnection;

    private const string NAME = 'Integritätsobjekt';

    private const string OWNER = 'Integrität Prüfung';

    protected function setUp(): void
    {
        self::bootKernel();
    }

    protected function tearDown(): void
    {
        $this->cleanUp();
        $this->closeSecondConnection();

        parent::tearDown();
    }

    /**
     * Der Kontakt verschwindet zwischen Nachschlagen und Speichern.
     *
     * Genau das Fenster aus dem Review: die Anwendung hat ihn eben noch
     * gefunden, eine andere Anfrage loescht ihn, und erst danach wird die
     * Zuordnung geschrieben. Die Anwendungspruefung ist hier schon vorbei.
     */
    public function testAContactDeletedMeanwhileCannotBecomeAnOwner(): void
    {
        $party = self::givenParty();
        $unit = self::givenUnit();

        // Eine andere Anfrage loescht den Kontakt und ist damit fertig.
        $this->secondConnection()->executeStatement('DELETE FROM party WHERE id = ?', [$party->id()]);

        new UnitOwner($unit, $party->id(), Mea::of('50', 1000));

        $this->expectException(ForeignKeyConstraintViolationException::class);
        self::properties()->save($unit->property());
    }

    /**
     * Und andersherum: wem etwas gehoert, den loescht auch die Datenbank nicht.
     *
     * Ueber die zweite Verbindung und damit an jeder Anwendungspruefung
     * vorbei — so, wie es ein Wartungsskript oder eine gleichzeitige Anfrage
     * taete.
     */
    public function testAContactWithAUnitCannotBeDeletedEvenPastTheApplication(): void
    {
        $party = self::givenParty();
        $unit = self::givenUnit();
        new UnitOwner($unit, $party->id(), Mea::of('50', 1000));
        self::properties()->save($unit->property());

        $this->expectException(ForeignKeyConstraintViolationException::class);
        $this->secondConnection()->executeStatement('DELETE FROM party WHERE id = ?', [$party->id()]);
    }

    private static function givenParty(): Party
    {
        $party = new Party(
            self::parties()->nextReference(),
            PartyKind::Person,
            self::OWNER,
            'Ida',
            PartyRoles::of([PartyRole::Owner]),
            Addresses::of([PostalAddress::of(AddressKind::Street, 'Musterweg 3', '12345', 'Musterstadt')]),
            ContactDetails::of([Email::fromString('integritaet@example.org')]),
        );
        self::parties()->save($party);

        return $party;
    }

    private static function givenUnit(): Unit
    {
        $property = new Property(
            self::properties()->nextNumber(),
            self::NAME,
            ManagementModes::of([ManagementMode::Weg]),
        );
        $unit = new Unit($property, 'WE 1');
        self::properties()->save($property);

        return $unit;
    }

    private function cleanUp(): void
    {
        $connection = self::entityManager()->getConnection();
        $connection->executeStatement('DELETE FROM property WHERE name = ?', [self::NAME]);
        $connection->executeStatement('DELETE FROM party WHERE name = ?', [self::OWNER]);
    }

    private static function properties(): PropertyRepository
    {
        $properties = self::getContainer()->get(PropertyRepository::class);
        self::assertInstanceOf(PropertyRepository::class, $properties);

        return $properties;
    }

    private static function parties(): PartyRepository
    {
        $parties = self::getContainer()->get(PartyRepository::class);
        self::assertInstanceOf(PartyRepository::class, $parties);

        return $parties;
    }
}
