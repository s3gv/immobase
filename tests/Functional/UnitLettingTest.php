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
use App\Module\Party\Domain\PartyPermissions;
use App\Module\Party\Domain\PartyRepository;
use App\Module\Party\Domain\PartyRole;
use App\Module\Party\Domain\PartyRoles;
use App\Module\Party\Domain\PostalAddress;
use App\Module\Property\Domain\ManagementMode;
use App\Module\Property\Domain\ManagementModes;
use App\Module\Property\Domain\Property;
use App\Module\Property\Domain\PropertyPermissions;
use App\Module\Property\Domain\PropertyRepository;
use App\Module\Property\Domain\Unit;
use App\Module\Tenancy\Application\SaveTenancy;
use App\Module\Tenancy\Domain\Tenancy;
use App\Module\Tenancy\Domain\TenancyRepository;
use App\Module\Tenancy\Domain\Tenant;
use App\Module\Tenancy\Domain\Term;
use App\Module\Tenancy\Domain\UnitAlreadyLet;
use App\Module\Tenancy\Domain\UnitLetInThatPeriod;
use App\Shared\Contact\Email;
use App\Shared\Identity\Uuid;
use DateTimeImmutable;
use Doctrine\DBAL\Exception\ForeignKeyConstraintViolationException;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Was ein Mietverhaeltnis fuer Einheit und Kontakt bedeutet.
 *
 * Beides sind Grenzen ueber Modulgrenzen hinweg: das Objektmodul erfaehrt
 * ueber UnitLinkSource, dass eine Einheit vermietet ist, die Stammdaten ueber
 * PartyLinkSource, dass jemand mietet. Und was die Anwendung dabei nicht
 * halten kann — zwei gleichzeitige Anfragen —, haelt die Datenbank.
 */
final class UnitLettingTest extends WebTestCase
{
    use ForgetsRateLimits;
    use SignsIn;
    use UsesASecondConnection;

    private const string PROPERTY = 'Vermietetes Objekt';

    private const string TENANT = 'Mietperson Prüfung';

    protected function tearDown(): void
    {
        self::cleanUp();
        $this->closeSecondConnection();
        self::removeTestUser();

        parent::tearDown();
    }

    /**
     * Eine vermietete Einheit bietet kein Loeschen mehr an.
     *
     * Geprueft wird, was jemand sieht: kein Formular, dafuer die Erklaerung
     * am abgeschalteten Knopf. Den Riegel im Controller mit einem
     * abgeschickten Formular zu pruefen ginge nicht — es gibt keins, aus dem
     * sich ein gueltiges Token holen liesse. Dass er greift, haelt
     * testALetUnitSurvivesADeleteThatSkipsTheApplication von der anderen
     * Seite fest.
     */
    public function testALetUnitOffersNoDeletion(): void
    {
        $client = self::manager([PropertyPermissions::VIEW, PropertyPermissions::DELETE]);
        $unit = self::givenLetUnit();

        $client->request('GET', '/objekte/'.$unit->property()->number().'?abschnitt=einheiten');
        $page = (string) $client->getResponse()->getContent();

        self::assertResponseIsSuccessful();
        self::assertStringNotContainsString(self::deleteUrl($unit), $page, 'Kein Löschen-Formular');
        self::assertStringContainsString('hängen Vorgänge', $page, 'Und die Erklärung dazu');
    }

    /** Und die Einheitenseite verweist auf das Mietverhaeltnis. */
    public function testTheUnitPageLinksToTheTenancy(): void
    {
        $client = self::manager([PropertyPermissions::VIEW]);
        $unit = self::givenLetUnit();
        $number = self::tenancyFor($unit)->number();

        $client->request('GET', '/objekte/'.$unit->property()->number().'/einheiten/'.$unit->number());

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('/miete/'.$number, (string) $client->getResponse()->getContent());
    }

    /** Wer mietet, laesst sich nicht loeschen — und die Seite sagt warum. */
    public function testATenantCannotBeDeleted(): void
    {
        $client = self::manager([PartyPermissions::VIEW, PartyPermissions::DELETE]);
        self::givenLetUnit();
        $party = self::givenParty();

        $client->request('GET', '/stammdaten/'.$party->reference());
        $page = (string) $client->getResponse()->getContent();

        self::assertResponseIsSuccessful();
        self::assertStringNotContainsString('/loeschen', $page, 'Kein Löschen-Formular');
        self::assertStringContainsString('Mieter einer Einheit', $page);
    }

    /**
     * Die Einheit verschwindet zwischen Nachschlagen und Speichern.
     *
     * Das Fenster, das keine Pruefung auf einer Verbindung schliesst: die
     * Anwendung hat die Einheit eben noch gefunden, eine andere Anfrage
     * loescht sie, und erst danach wird das Mietverhaeltnis geschrieben.
     */
    public function testAUnitDeletedMeanwhileCannotBeLet(): void
    {
        self::manager([PropertyPermissions::VIEW]);
        $unit = self::givenUnit();
        $id = $unit->id();

        $this->secondConnection()->executeStatement('DELETE FROM property_unit WHERE id = ?', [$id]);

        self::entityManager()->clear();
        $tenancy = new Tenancy(self::tenancies()->nextNumber(), $id);

        $this->expectException(ForeignKeyConstraintViolationException::class);
        self::tenancies()->save($tenancy);
    }

    /**
     * Und andersherum: eine vermietete Einheit loescht auch die Datenbank
     * nicht — ueber die zweite Verbindung und damit an jeder
     * Anwendungspruefung vorbei.
     */
    public function testALetUnitSurvivesADeleteThatSkipsTheApplication(): void
    {
        self::manager([PropertyPermissions::VIEW]);
        $unit = self::givenLetUnit();

        $this->expectException(ForeignKeyConstraintViolationException::class);
        $this->secondConnection()->executeStatement('DELETE FROM property_unit WHERE id = ?', [$unit->id()]);
    }

    /**
     * Zwei Anfragen aktivieren im selben Augenblick — eine verliert.
     *
     * Die Vorabpruefung schliesst das Fenster nicht; dafuer gibt es den
     * partiellen eindeutigen Index. Was die Datenbank dann meldet, ist
     * fachlich eine Absage und kein Fehler: roh weitergereicht waere es ein
     * 500er statt „bereits vermietet".
     *
     * Das Fenster laesst sich nur mit einer festgehaltenen Momentaufnahme
     * zeigen: unter REPEATABLE READ sieht die eigene Abfrage die Einheit noch
     * frei, waehrend die andere Anfrage sie laengst vermietet hat. Genau so
     * verhaelt sich der Betrieb, nur unvorhersehbar.
     */
    public function testALostRaceOnActivationBecomesARefusal(): void
    {
        self::manager([PropertyPermissions::VIEW]);
        $unit = self::givenUnit();
        $tenancy = new Tenancy(self::tenancies()->nextNumber(), $unit->id());
        new Tenant($tenancy, self::givenParty()->id());
        self::tenancies()->save($tenancy);

        $connection = self::entityManager()->getConnection();
        $connection->beginTransaction();
        $connection->executeStatement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');

        try {
            // Der Blick in die Datenbank steht: die Einheit ist frei.
            self::assertNull(self::tenancies()->activeFor($unit->id()));

            // Und im selben Augenblick vermietet sie eine andere Anfrage.
            $this->letByAnotherRequest($unit->id());

            self::save()->complete($tenancy);
            self::fail('Das zweite aktive Mietverhältnis kam durch.');
        } catch (UnitAlreadyLet $problem) {
            // Der Aufrufer muss weiterleiten statt zu zeichnen: nach dem
            // fehlgeschlagenen Flush ist der Entity Manager geschlossen.
            self::assertTrue($problem->whileSaving, 'Die Absage kommt aus der Datenbank');
        } finally {
            $connection->rollBack();
        }
    }

    /**
     * Der Nachmieter wird erfasst, waehrend der Vormieter noch wohnt.
     *
     * Das laufende Mietverhaeltnis ist unbefristet und reicht damit bis in
     * alle Zukunft. Zaehlten Entwuerfe beim Ueberschneidungsschutz mit,
     * liesse sich nie einer vorab anlegen.
     */
    public function testASuccessorMayBeDraftedWhileTheUnitIsLet(): void
    {
        self::manager([PropertyPermissions::VIEW]);
        $unit = self::givenLetUnit();

        $draft = self::draftFor($unit, '2027-01-01', null);

        self::assertTrue($draft->status()->isDraft());
        self::assertCount(2, self::tenancies()->forUnits([$unit->id()])[$unit->id()] ?? []);
    }

    /** Zwei Mietverhaeltnisse duerfen sich nicht ueberschneiden. */
    public function testAnOverlappingPeriodIsRefused(): void
    {
        self::manager([PropertyPermissions::VIEW]);
        $unit = self::givenUnit();
        self::endedTenancy($unit, '2024-01-01', '2024-12-31');

        $second = self::draftFor($unit, '2024-06-01', '2025-05-31');

        $this->expectException(UnitLetInThatPeriod::class);
        self::save()->complete($second);
    }

    /** Nahtlos anschliessen geht: das eine endet, am naechsten Tag beginnt das andere. */
    public function testASeamlessSuccessionIsAllowed(): void
    {
        self::manager([PropertyPermissions::VIEW]);
        $unit = self::givenUnit();
        self::endedTenancy($unit, '2024-01-01', '2025-03-31');

        $second = self::draftFor($unit, '2025-04-01', null);
        self::save()->complete($second);

        self::assertTrue($second->status()->isActive());
    }

    /**
     * Und derselbe Wettlauf noch einmal, eine Ebene tiefer.
     *
     * Der eindeutige Teilindex schuetzt nur die Gegenwart. Ueberschneidende
     * Zeitraeume haelt allein die Ausschlussbedingung auf — auch die muss als
     * Absage ankommen und nicht als 500er.
     */
    public function testALostRaceOnThePeriodBecomesARefusal(): void
    {
        self::manager([PropertyPermissions::VIEW]);
        $unit = self::givenUnit();
        $draft = self::draftFor($unit, '2024-06-01', '2024-12-31');

        $connection = self::entityManager()->getConnection();
        $connection->beginTransaction();
        $connection->executeStatement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');

        try {
            // Der Blick in die Datenbank steht: der Zeitraum ist frei.
            self::assertNull(self::tenancies()->overlapping(
                $unit->id(),
                new DateTimeImmutable('2024-06-01'),
                new DateTimeImmutable('2024-12-31'),
            ));

            $this->letByAnotherRequest($unit->id(), '2024-01-01', '2024-12-31');

            self::save()->complete($draft);
            self::fail('Das überschneidende Mietverhältnis kam durch.');
        } catch (UnitLetInThatPeriod $problem) {
            self::assertTrue($problem->whileSaving, 'Die Absage kommt aus der Datenbank');
        } finally {
            $connection->rollBack();
        }
    }

    protected static function testEmail(): string
    {
        return 'vermietung@example.org';
    }

    /**
     * Ein aktives Mietverhaeltnis fuer dieselbe Einheit, an der Anwendung
     * vorbei — so, wie es eine gleichzeitige Anfrage hinterliesse.
     */
    private function letByAnotherRequest(string $unitId, ?string $from = null, ?string $to = null): void
    {
        $this->secondConnection()->executeStatement(
            "INSERT INTO tenancy (id, number, unit_id, status, starts_on, ends_on, payment_method, payment_due, note)
             VALUES (?, ?, ?, ?, ?, ?, 'transfer', 'third_working_day', '')",
            [
                Uuid::v4(),
                self::tenancies()->nextNumber(),
                $unitId,
                // Ohne Zeitraum geht es um das aktive Mietverhaeltnis, mit
                // Zeitraum um die Ueberschneidung — und die trifft auch ein
                // beendetes.
                null === $from ? 'active' : 'ended',
                $from,
                $to,
            ],
        );
    }

    /** Ein Entwurf mit Laufzeit — noch nicht aktiv, aber schon erfasst. */
    private static function draftFor(Unit $unit, string $from, ?string $to): Tenancy
    {
        $tenancy = new Tenancy(self::tenancies()->nextNumber(), $unit->id());
        new Tenant($tenancy, self::givenParty()->id());
        $tenancy->runFor(Term::of(
            new DateTimeImmutable($from),
            null === $to ? null : new DateTimeImmutable($to),
            null,
            null,
        ));
        self::tenancies()->save($tenancy);

        return $tenancy;
    }

    /** Ein Mietverhaeltnis, das gelaufen und beendet ist. */
    private static function endedTenancy(Unit $unit, string $from, string $to): Tenancy
    {
        $tenancy = self::draftFor($unit, $from, $to);
        $tenancy->activate();
        $tenancy->endOn(new DateTimeImmutable($to));
        self::tenancies()->save($tenancy);

        return $tenancy;
    }

    private static function save(): SaveTenancy
    {
        $save = self::getContainer()->get(SaveTenancy::class);
        self::assertInstanceOf(SaveTenancy::class, $save);

        return $save;
    }

    /**
     * @param list<string> $permissions
     */
    private static function manager(array $permissions): KernelBrowser
    {
        return self::signedInWith($permissions);
    }

    private static function deleteUrl(Unit $unit): string
    {
        return '/objekte/'.$unit->property()->number().'/einheiten/'.$unit->number().'/loeschen';
    }

    private static function givenLetUnit(): Unit
    {
        $unit = self::givenUnit();

        if (null !== self::tenancies()->activeFor($unit->id())) {
            return $unit;
        }

        $tenancy = new Tenancy(self::tenancies()->nextNumber(), $unit->id());
        new Tenant($tenancy, self::givenParty()->id());
        $tenancy->activate();
        self::tenancies()->save($tenancy);

        return $unit;
    }

    private static function tenancyFor(Unit $unit): Tenancy
    {
        $tenancy = self::tenancies()->activeFor($unit->id());
        self::assertNotNull($tenancy);

        return $tenancy;
    }

    private static function givenUnit(): Unit
    {
        $property = self::propertyByName();

        if (null !== $property && [] !== $property->units()) {
            return $property->units()[0];
        }

        $fresh = new Property(
            self::properties()->nextNumber(),
            self::PROPERTY,
            ManagementModes::of([ManagementMode::Rental]),
        );
        $unit = new Unit($fresh, 'WE 1');
        $fresh->managedAs($fresh->management()->activated());
        self::properties()->save($fresh);

        return $unit;
    }

    private static function propertyByName(): ?Property
    {
        $id = self::entityManager()->getConnection()->fetchOne(
            'SELECT id FROM property WHERE name = ?',
            [self::PROPERTY],
        );

        return \is_string($id) ? self::properties()->byId($id) : null;
    }

    private static function givenParty(): Party
    {
        $existing = self::parties()->search(self::TENANT, 1);

        if ([] !== $existing) {
            return $existing[0];
        }

        $party = new Party(
            self::parties()->nextReference(),
            PartyKind::Person,
            self::TENANT,
            'Mika',
            PartyRoles::of([PartyRole::Tenant]),
            Addresses::of([PostalAddress::of(AddressKind::Street, 'Musterweg 5', '12345', 'Musterstadt')]),
            ContactDetails::of([Email::fromString('mietperson@example.org')]),
        );
        self::parties()->save($party);

        return $party;
    }

    private static function cleanUp(): void
    {
        if (null === self::$kernel) {
            return;
        }

        $connection = self::entityManager()->getConnection();
        $connection->executeStatement(
            'DELETE FROM tenancy WHERE unit_id IN (
                 SELECT u.id FROM property_unit u JOIN property p ON p.id = u.property_id WHERE p.name = ?
             )',
            [self::PROPERTY],
        );
        $connection->executeStatement('DELETE FROM property WHERE name = ?', [self::PROPERTY]);
        $connection->executeStatement('DELETE FROM party WHERE name = ?', [self::TENANT]);
    }

    private static function tenancies(): TenancyRepository
    {
        $tenancies = self::getContainer()->get(TenancyRepository::class);
        self::assertInstanceOf(TenancyRepository::class, $tenancies);

        return $tenancies;
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
