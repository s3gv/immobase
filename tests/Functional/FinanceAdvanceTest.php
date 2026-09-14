<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Module\Finance\Application\SaveHouseMoney;
use App\Module\Finance\Contract\Interval;
use App\Module\Finance\Domain\FinancePermissions;
use App\Module\Finance\Domain\HouseMoneyRepository;
use App\Module\Finance\Domain\StepAlreadyStartsThatDay;
use App\Module\Finance\UserInterface\Controller\AdvanceController;
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
use App\Module\Property\Domain\Property;
use App\Module\Property\Domain\PropertyRepository;
use App\Module\Property\Domain\Unit;
use App\Module\Tenancy\Contract\TenancyDirectory;
use App\Module\Tenancy\Domain\Rent;
use App\Module\Tenancy\Domain\RentStep;
use App\Module\Tenancy\Domain\Tenancy;
use App\Module\Tenancy\Domain\TenancyRepository;
use App\Module\Tenancy\Domain\Tenant;
use App\Module\Tenancy\Domain\Term;
use App\Shared\Contact\Email;
use App\Shared\Money\Money;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Zwei Sorten Vorauszahlung auf einer Seite.
 *
 * Das Hausgeld gehoert den Finanzen. Die Nebenkosten stehen im Mietvertrag
 * und werden von dort gelesen — eine zweite Fassung zu fuehren hiesse,
 * dieselbe Zahl zweimal zu haben.
 */
final class FinanceAdvanceTest extends WebTestCase
{
    use ForgetsRateLimits;
    use SignsIn;
    use UsesASecondConnection;

    private const string PROPERTY = 'Vorauszahlungsobjekt Prüfung';

    private const string TENANT = 'Vorauszahlungsmieter Prüfung';

    protected function tearDown(): void
    {
        self::cleanUp();
        self::removeTestUser();

        parent::tearDown();
    }

    /** Was heute gilt, ist die letzte Stufe mit Datum <= heute. */
    public function testTheCurrentStepIsTheLastOneThatHasStarted(): void
    {
        $client = self::editor();
        $unit = self::givenUnit();

        self::step($client, $unit->id(), ['startsOn' => '2026-01-01', 'amount' => '320,00']);
        self::step($client, $unit->id(), ['startsOn' => '2099-01-01', 'amount' => '400,00']);

        $schedule = self::steps()->forUnits([$unit->id()])[$unit->id()] ?? null;
        self::assertNotNull($schedule);
        self::assertSame(32000, $schedule->amountOn(new DateTimeImmutable('today'))?->cents());
    }

    /** Zu einem Tag hoechstens eine Stufe. */
    public function testTwoStepsOnTheSameDayAreRefused(): void
    {
        $client = self::editor();
        $unit = self::givenUnit();

        self::step($client, $unit->id(), ['startsOn' => '2026-01-01', 'amount' => '320,00']);
        self::step($client, $unit->id(), ['startsOn' => '2026-01-01', 'amount' => '400,00']);

        $schedule = self::steps()->forUnits([$unit->id()])[$unit->id()] ?? null;
        self::assertNotNull($schedule);
        self::assertCount(1, $schedule->steps(), 'Die Stufe steht einmal da');
    }

    /**
     * Die Nebenkosten kommen aus dem Mietverhaeltnis.
     *
     * Gefragt wird nach einem Tag und nicht nach einem Status: fuer die
     * Abrechnung eines vergangenen Jahres ist das gesuchte Mietverhaeltnis
     * laengst beendet.
     */
    public function testTheOperatingCostsComeFromTheTenancy(): void
    {
        self::bootKernel();
        $unit = self::givenUnit();
        self::givenTenancy($unit, '2026-01-01', null);

        $advances = self::tenancies()->advancesFor([$unit->id()], new DateTimeImmutable('2026-07-01'));

        self::assertArrayHasKey($unit->id(), $advances);
        self::assertSame(15000, $advances[$unit->id()]->operatingCosts->cents());
        self::assertSame(4000, $advances[$unit->id()]->heating->cents());
        self::assertSame(19000, $advances[$unit->id()]->total()->cents());
    }

    /** Auch ein beendetes zaehlt — fuer den Tag, an dem es lief. */
    public function testAnEndedTenancyStillAnswersForItsOwnDays(): void
    {
        self::bootKernel();
        $unit = self::givenUnit();
        self::givenTenancy($unit, '2024-01-01', '2024-12-31');

        $during = self::tenancies()->advancesFor([$unit->id()], new DateTimeImmutable('2024-07-01'));
        $after = self::tenancies()->advancesFor([$unit->id()], new DateTimeImmutable('2026-07-01'));

        self::assertArrayHasKey($unit->id(), $during);
        self::assertSame([], $after, 'Nach dem Ende gibt es nichts mehr zu zahlen');
    }

    /** Die Seite zeigt beide Sorten nebeneinander. */
    public function testThePageShowsBothKinds(): void
    {
        $client = self::editor();
        $unit = self::givenUnit();
        self::givenTenancy($unit, '2026-01-01', null);
        self::step($client, $unit->id(), ['startsOn' => '2026-01-01', 'amount' => '320,00']);

        $client->request('GET', '/finanzen/vorauszahlungen');
        $body = (string) $client->getResponse()->getContent();

        self::assertStringContainsString('320,00', $body, 'Das Hausgeld steht da');
        self::assertStringContainsString('190,00', $body, 'Und die Nebenkosten aus der Miete');
    }

    /**
     * Wer keine Mietrechte hat, bekommt keinen Link dorthin.
     *
     * Ein Link, der zuverlaessig in eine Fehlermeldung fuehrt, ist
     * schlechter als keiner. Die Zahl steht trotzdem da — sie ist eine
     * Finanzangabe.
     */
    public function testWithoutTenancyRightsThereIsNoLink(): void
    {
        $client = self::signedInWith([FinancePermissions::VIEW]);
        $unit = self::givenUnit();
        self::givenTenancy($unit, '2026-01-01', null);

        $client->request('GET', '/finanzen/vorauszahlungen/'.$unit->id());
        $body = (string) $client->getResponse()->getContent();

        self::assertStringContainsString('190,00', $body, 'Die Zahl steht da');
        self::assertStringNotContainsString('href="/miete/', $body, 'Aber ohne Sprung dorthin');
    }

    /** Jeder Abschnitt der Einheit zeichnet sich. */
    public function testEverySectionOfTheUnitRenders(): void
    {
        $client = self::editor();
        $unit = self::givenUnit();
        self::givenTenancy($unit, '2026-01-01', null);

        foreach (AdvanceController::SECTIONS as $section) {
            $client->request('GET', '/finanzen/vorauszahlungen/'.$unit->id().'?abschnitt='.$section);
            self::assertResponseIsSuccessful('Abschnitt '.$section);
        }
    }

    /**
     * Zwei gleichzeitige Anfragen — die zweite bekommt eine Antwort.
     *
     * Zwischen der Pruefung und dem Speichern liegt eine Luecke. Die eigene
     * Verbindung liest in einer Transaktion mit stabilem Blick; die zweite
     * legt die Stufe darin an. Die Pruefung sieht sie deshalb nicht mehr und
     * laesst durch — der eindeutige Index nicht. Ohne Uebersetzung waere das
     * ein 500er statt der Meldung, die es fuer genau diesen Fall gibt.
     */
    public function testAStepAddedInTheMeantimeIsRefusedReadably(): void
    {
        self::bootKernel();
        $unit = self::givenUnit();
        $connection = self::entityManager()->getConnection();
        $connection->beginTransaction();
        $connection->executeStatement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');

        try {
            // Der Blick in die Datenbank steht: zu diesem Tag gibt es nichts.
            self::assertSame([], self::steps()->forUnits([$unit->id()]));

            $this->addedByAnotherRequest($unit->id());

            self::save()->add(
                $unit->id(),
                new DateTimeImmutable('2026-01-01'),
                Money::fromCents(40000),
                Interval::Monthly,
            );
            self::fail('Die zweite Stufe zum selben Tag kam durch.');
        } catch (StepAlreadyStartsThatDay $problem) {
            self::assertNotSame('', $problem->getMessage(), 'Und zwar mit einer lesbaren Meldung');
        } finally {
            $connection->rollBack();
            $this->closeSecondConnection();
        }
    }

    protected static function testEmail(): string
    {
        return 'vorauszahlung@example.org';
    }

    /** Was im selben Augenblick eine andere Anfrage anlegt. */
    private function addedByAnotherRequest(string $unitId): void
    {
        $this->secondConnection()->executeStatement(
            'INSERT INTO finance_house_money (id, unit_id, starts_on, amount, pay_interval, note)
             VALUES (gen_random_uuid(), ?, ?, 32000, ?, ?)',
            [$unitId, '2026-01-01', 'monthly', ''],
        );
    }

    private static function editor(): KernelBrowser
    {
        return self::signedInWith([FinancePermissions::VIEW, FinancePermissions::EDIT]);
    }

    /**
     * @param array<string, string> $fields
     */
    private static function step(KernelBrowser $client, string $unitId, array $fields): void
    {
        // Die Staffel steht auf der Einheit, nicht in der Liste — dort liegt
        // auch das Formular, dessen Token hier gebraucht wird.
        $crawler = $client->request('GET', '/finanzen/vorauszahlungen/'.$unitId.'?abschnitt=staffel');
        $token = (string) $crawler->filter('main input[name="_token"]')->first()->attr('value');

        $client->request('POST', '/finanzen/vorauszahlungen/'.$unitId, [
            ...$fields,
            'interval' => 'monthly',
            '_token' => $token,
        ]);
    }

    private static function givenUnit(): Unit
    {
        $existing = self::propertyByName();

        if (null !== $existing && [] !== $existing->units()) {
            return $existing->units()[0];
        }

        $property = new Property(
            self::properties()->nextNumber(),
            self::PROPERTY,
            ManagementModes::of([ManagementMode::Weg]),
        );
        $unit = new Unit($property, 'WE 1');
        $property->managedAs($property->management()->activated());
        self::properties()->save($property);

        return $unit;
    }

    private static function givenTenancy(Unit $unit, string $from, ?string $to): void
    {
        $tenancy = new Tenancy(self::tenancyRepository()->nextNumber(), $unit->id());
        new Tenant($tenancy, self::givenParty()->id());
        $tenancy->runFor(Term::of(
            new DateTimeImmutable($from),
            null === $to ? null : new DateTimeImmutable($to),
            null,
            null,
        ));
        $tenancy->activate();

        new RentStep($tenancy, new DateTimeImmutable($from), Rent::of(
            Money::fromCents(78000),
            Money::fromCents(15000),
            Money::fromCents(4000),
            Money::zero(),
        ));

        if (null !== $to) {
            $tenancy->endOn(new DateTimeImmutable($to));
        }

        self::tenancyRepository()->save($tenancy);
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
            ContactDetails::of([Email::fromString('vorauszahlung.mieter@example.org')]),
        );
        self::parties()->save($party);

        return $party;
    }

    private static function propertyByName(): ?Property
    {
        $id = self::entityManager()->getConnection()->fetchOne(
            'SELECT id FROM property WHERE name = ?',
            [self::PROPERTY],
        );

        return \is_string($id) ? self::properties()->byId($id) : null;
    }

    private static function save(): SaveHouseMoney
    {
        $save = self::getContainer()->get(SaveHouseMoney::class);
        self::assertInstanceOf(SaveHouseMoney::class, $save);

        return $save;
    }

    private static function steps(): HouseMoneyRepository
    {
        $repository = self::getContainer()->get(HouseMoneyRepository::class);
        self::assertInstanceOf(HouseMoneyRepository::class, $repository);

        return $repository;
    }

    private static function tenancies(): TenancyDirectory
    {
        $directory = self::getContainer()->get(TenancyDirectory::class);
        self::assertInstanceOf(TenancyDirectory::class, $directory);

        return $directory;
    }

    private static function tenancyRepository(): TenancyRepository
    {
        $repository = self::getContainer()->get(TenancyRepository::class);
        self::assertInstanceOf(TenancyRepository::class, $repository);

        return $repository;
    }

    private static function properties(): PropertyRepository
    {
        $repository = self::getContainer()->get(PropertyRepository::class);
        self::assertInstanceOf(PropertyRepository::class, $repository);

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
        $units = 'SELECT u.id FROM property_unit u JOIN property p ON p.id = u.property_id WHERE p.name = ?';
        $connection->executeStatement('DELETE FROM finance_house_money WHERE unit_id IN ('.$units.')', [self::PROPERTY]);
        $connection->executeStatement('DELETE FROM tenancy WHERE unit_id IN ('.$units.')', [self::PROPERTY]);
        $connection->executeStatement('DELETE FROM property WHERE name = ?', [self::PROPERTY]);
        $connection->executeStatement('DELETE FROM party WHERE name = ?', [self::TENANT]);
    }
}
