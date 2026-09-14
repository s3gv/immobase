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
use App\Module\Property\Application\CloseProperty;
use App\Module\Property\Application\PreviewClosure;
use App\Module\Property\Domain\ManagementMode;
use App\Module\Property\Domain\ManagementModes;
use App\Module\Property\Domain\Property;
use App\Module\Property\Domain\PropertyNeedsAClosingDate;
use App\Module\Property\Domain\PropertyPermissions;
use App\Module\Property\Domain\PropertyRepository;
use App\Module\Property\Domain\PropertyStatus;
use App\Module\Property\Domain\Unit;
use App\Module\Property\Domain\UnitRepository;
use App\Module\Property\Domain\UnitStatus;
use App\Module\Tenancy\Domain\Tenancy;
use App\Module\Tenancy\Domain\TenancyRepository;
use App\Module\Tenancy\Domain\TenancyStatus;
use App\Module\Tenancy\Domain\Tenant;
use App\Module\Tenancy\Domain\Term;
use App\Shared\Contact\Email;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Ein Objekt abgeben — und was dabei mitgeht.
 *
 * Der Verwaltervertrag laeuft aus. Das Objekt ist weg, die Arbeit daran auch,
 * aber die Abrechnung des laufenden Jahres steht noch aus. Beenden muss
 * deshalb durchgreifen und darf nichts loeschen.
 *
 * Dass die Mietverhaeltnisse dabei enden, macht das Objektmodul nicht selbst:
 * es sagt nur, was passiert ist. Diese Tests pruefen die Kette von aussen —
 * genau so, wie sie im Betrieb laeuft.
 */
final class PropertyClosureTest extends WebTestCase
{
    use ForgetsRateLimits;
    use SignsIn;

    private const string PROPERTY = 'Abgewickeltes Objekt';

    private const string TENANT = 'Abwicklungsmieter Prüfung';

    protected function tearDown(): void
    {
        self::cleanUp();
        self::removeTestUser();

        parent::tearDown();
    }

    public function testClosingAPropertyEndsItsUnitsAndTenancies(): void
    {
        self::bootKernel();
        $unit = self::givenLetUnit('2026-01-01', null);

        self::close()->on($unit->property(), new DateTimeImmutable('2026-12-31'));

        self::assertSame(PropertyStatus::Ended, self::property()->status());
        self::assertSame(UnitStatus::Ended, self::freshUnit()->status());

        $tenancy = self::tenancyFor($unit);
        self::assertSame(TenancyStatus::Ended, $tenancy->status());
        self::assertSame('2026-12-31', $tenancy->term()->endsOn()?->format('Y-m-d'));
    }

    /** Der Stichtag setzt ein Ende, wo keines steht — er verschiebt keines. */
    public function testAnEarlierEndSurvivesTheClosure(): void
    {
        self::bootKernel();
        $unit = self::givenLetUnit('2026-01-01', '2026-06-30');

        self::close()->on($unit->property(), new DateTimeImmutable('2026-12-31'));

        self::assertSame('2026-06-30', self::tenancyFor($unit)->term()->endsOn()?->format('Y-m-d'));
    }

    /** Eine einzelne Einheit geht auch — bei SEV ist das der Normalfall. */
    public function testAUnitCanBeHandedOverWhileThePropertyStays(): void
    {
        self::bootKernel();
        $unit = self::givenLetUnit('2026-01-01', null);

        self::close()->unitOn($unit, new DateTimeImmutable('2026-09-30'));

        self::assertSame(PropertyStatus::Active, self::property()->status(), 'Das Objekt bleibt');
        self::assertSame(UnitStatus::Ended, self::freshUnit()->status());
        self::assertSame('2026-09-30', self::tenancyFor($unit)->term()->endsOn()?->format('Y-m-d'));
    }

    /** Ohne Stichtag wird nichts abgewickelt. */
    public function testClosingNeedsADate(): void
    {
        self::bootKernel();
        $unit = self::givenLetUnit('2026-01-01', null);

        try {
            self::close()->on($unit->property(), null);
            self::fail('Ohne Stichtag durchgegangen.');
        } catch (PropertyNeedsAClosingDate) {
            self::assertSame(PropertyStatus::Active, self::property()->status());
        }
    }

    /**
     * Wieder aufnehmen holt nur das Objekt zurueck.
     *
     * Ein beendetes Mietverhaeltnis wieder aufleben zu lassen waere geraten:
     * es kann in der Zwischenzeit richtig beendet worden sein.
     */
    public function testReopeningLeavesTheUnitsClosed(): void
    {
        self::bootKernel();
        $unit = self::givenLetUnit('2026-01-01', null);
        self::close()->on($unit->property(), new DateTimeImmutable('2026-12-31'));

        self::close()->reopen(self::property());

        self::assertSame(PropertyStatus::Active, self::property()->status());
        self::assertSame(UnitStatus::Ended, self::freshUnit()->status(), 'Die Einheit bleibt beendet');
        self::assertSame(TenancyStatus::Ended, self::tenancyFor($unit)->status());
    }

    /** Ein Entwurf am selben Objekt wird nicht beendet — er ist keine Vermietung. */
    public function testADraftIsLeftAlone(): void
    {
        self::bootKernel();
        $unit = self::givenLetUnit('2026-01-01', null);
        $draft = new Tenancy(self::tenancies()->nextNumber(), $unit->id());
        new Tenant($draft, self::givenParty()->id());
        self::tenancies()->save($draft);

        self::close()->on($unit->property(), new DateTimeImmutable('2026-12-31'));

        self::entityManager()->clear();
        $found = self::tenancies()->byId($draft->id());
        self::assertNotNull($found);
        self::assertSame(TenancyStatus::Draft, $found->status());
    }

    /**
     * Die Vorschau nennt dieselben Zahlen, die danach wirklich beendet sind.
     *
     * Ein Mietverhaeltnis, das schon beendet ist, gehoert nicht dazu: es
     * endet nicht noch einmal, und die Bestaetigung wuerde sonst mehr
     * versprechen als die Abwicklung tut.
     */
    public function testThePreviewCountsOnlyWhatWillEnd(): void
    {
        self::bootKernel();
        $unit = self::givenLetUnit('2026-01-01', null);
        $over = new Tenancy(self::tenancies()->nextNumber(), $unit->id());
        new Tenant($over, self::givenParty()->id());
        $over->runFor(Term::of(new DateTimeImmutable('2024-01-01'), null, null, null));
        $over->activate();
        $over->endOn(new DateTimeImmutable('2024-12-31'));
        self::tenancies()->save($over);

        $preview = self::preview()->forProperty(self::property());

        self::assertSame(1, $preview['tenancy.unit.count'] ?? 0, 'Nur das laufende zählt');
    }

    /**
     * Was null ist, steht nicht in der Vorschau.
     *
     * Ein Objekt, dessen Einheiten schon abgegeben sind, hat nichts
     * mitzunehmen — „0 Einheiten" waere keine Zeile, sondern eine fehlende.
     */
    public function testThePreviewLeavesOutWhatIsAlreadyGone(): void
    {
        self::bootKernel();
        $unit = self::givenLetUnit('2026-01-01', null);
        self::close()->on($unit->property(), new DateTimeImmutable('2026-12-31'));
        self::close()->reopen(self::property());

        self::assertSame([], self::preview()->forProperty(self::property()));
    }

    /** Eine abgegebene Einheit steht nicht mehr zur Wahl. */
    public function testAClosedUnitIsNoLongerOffered(): void
    {
        self::bootKernel();
        $unit = self::givenLetUnit('2026-01-01', null);

        self::assertNotSame([], self::units()->search('Abgewickeltes', 10));

        self::close()->unitOn($unit, new DateTimeImmutable('2026-09-30'));

        self::assertSame([], self::units()->search('Abgewickeltes', 10), 'Nicht mehr in der Suche');
    }

    /** Auch die Objektliste zeigt Abgewickeltes erst auf Wunsch. */
    public function testTheListHidesClosedPropertiesUntilAsked(): void
    {
        $client = self::signedIn();
        $unit = self::givenLetUnit('2026-01-01', null);
        self::close()->on($unit->property(), new DateTimeImmutable('2026-12-31'));

        $client->request('GET', '/objekte');
        self::assertStringNotContainsString(self::PROPERTY, (string) $client->getResponse()->getContent());

        $client->request('GET', '/objekte?vergangene=1');
        self::assertStringContainsString(self::PROPERTY, (string) $client->getResponse()->getContent());
    }

    protected static function testEmail(): string
    {
        return 'abwicklung@example.org';
    }

    private static function close(): CloseProperty
    {
        $close = self::getContainer()->get(CloseProperty::class);
        self::assertInstanceOf(CloseProperty::class, $close);

        return $close;
    }

    private static function signedIn(): KernelBrowser
    {
        return self::signedInWith([PropertyPermissions::VIEW]);
    }

    private static function preview(): PreviewClosure
    {
        $preview = self::getContainer()->get(PreviewClosure::class);
        self::assertInstanceOf(PreviewClosure::class, $preview);

        return $preview;
    }

    private static function units(): UnitRepository
    {
        $units = self::getContainer()->get(UnitRepository::class);
        self::assertInstanceOf(UnitRepository::class, $units);

        return $units;
    }

    /** Eine vermietete Einheit in einem frischen Objekt. */
    private static function givenLetUnit(string $from, ?string $to): Unit
    {
        $property = new Property(
            self::properties()->nextNumber(),
            self::PROPERTY,
            ManagementModes::of([ManagementMode::Rental]),
        );
        $unit = new Unit($property, 'WE 1');
        $property->managedAs($property->management()->activated());
        self::properties()->save($property);

        $tenancy = new Tenancy(self::tenancies()->nextNumber(), $unit->id());
        new Tenant($tenancy, self::givenParty()->id());
        $tenancy->runFor(Term::of(
            new DateTimeImmutable($from),
            null === $to ? null : new DateTimeImmutable($to),
            null,
            null,
        ));
        $tenancy->activate();
        self::tenancies()->save($tenancy);

        return $unit;
    }

    private static function property(): Property
    {
        self::entityManager()->clear();
        $id = self::entityManager()->getConnection()->fetchOne(
            'SELECT id FROM property WHERE name = ?',
            [self::PROPERTY],
        );
        $found = \is_string($id) ? self::properties()->byId($id) : null;
        self::assertNotNull($found, 'Das Objekt fehlt.');

        return $found;
    }

    private static function freshUnit(): Unit
    {
        $units = self::property()->units();
        self::assertNotSame([], $units);

        return $units[0];
    }

    private static function tenancyFor(Unit $unit): Tenancy
    {
        self::entityManager()->clear();
        $found = self::tenancies()->forUnits([$unit->id()])[$unit->id()] ?? [];
        self::assertNotSame([], $found, 'Kein Mietverhältnis zu dieser Einheit.');

        return $found[0];
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
            ContactDetails::of([Email::fromString('abwicklung@example.org')]),
        );
        self::parties()->save($party);

        return $party;
    }

    private static function properties(): PropertyRepository
    {
        $repository = self::getContainer()->get(PropertyRepository::class);
        self::assertInstanceOf(PropertyRepository::class, $repository);

        return $repository;
    }

    private static function tenancies(): TenancyRepository
    {
        $repository = self::getContainer()->get(TenancyRepository::class);
        self::assertInstanceOf(TenancyRepository::class, $repository);

        return $repository;
    }

    private static function parties(): PartyRepository
    {
        $repository = self::getContainer()->get(PartyRepository::class);
        self::assertInstanceOf(PartyRepository::class, $repository);

        return $repository;
    }

    private static function entityManager(): EntityManagerInterface
    {
        $manager = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $manager);

        return $manager;
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
}
