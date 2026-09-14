<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Module\Finance\Application\BookReserve;
use App\Module\Finance\Domain\AdvanceKind;
use App\Module\Finance\Domain\AdvancePayment;
use App\Module\Finance\Domain\AdvancePaymentRepository;
use App\Module\Finance\Domain\AlreadyReversed;
use App\Module\Finance\Domain\FinancePermissions;
use App\Module\Finance\Domain\LevyComesFromAResolution;
use App\Module\Finance\Domain\ReserveBalance;
use App\Module\Finance\Domain\ReserveMovementKind;
use App\Module\Finance\Domain\ReserveMovementRepository;
use App\Module\Finance\Domain\ReserveNeedsAUnit;
use App\Module\Finance\Domain\ReserveOnlyForWeg;
use App\Module\Finance\Domain\SecondOpeningBalance;
use App\Module\Finance\Domain\UnitBelongsElsewhere;
use App\Module\Finance\UserInterface\Controller\ReserveController;
use App\Module\Property\Domain\ManagementMode;
use App\Module\Property\Domain\ManagementModes;
use App\Module\Property\Domain\Property;
use App\Module\Property\Domain\PropertyRepository;
use App\Module\Property\Domain\Unit;
use App\Shared\Money\Money;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use InvalidArgumentException;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Die Erhaltungsruecklage: vier Regeln, und jede hat einen Grund.
 *
 * Sie gehoert der Gemeinschaft, also gibt es sie nur bei WEG. Wer eingezahlt
 * hat, muss nachvollziehbar sein. Zinsen duerfen negativ sein, alles andere
 * nicht. Und einen Anfangsbestand gibt es hoechstens einmal.
 */
final class FinanceReserveTest extends WebTestCase
{
    use SignsIn;
    use UsesASecondConnection;

    private const string WEG = 'Rücklagenobjekt Prüfung';

    private const string RENTAL = 'Mietobjekt ohne Rücklage Prüfung';

    private const string OTHER_WEG = 'Zweites Rücklagenobjekt Prüfung';

    protected function tearDown(): void
    {
        self::cleanUp();
        self::removeTestUser();

        parent::tearDown();
    }

    /** Der Stand ist die Summe der Bewegungen — Entnahmen mindern ihn. */
    public function testTheBalanceIsTheSumOfItsMovements(): void
    {
        self::bootKernel();
        $property = self::givenProperty(self::WEG, ManagementMode::Weg);
        $unit = self::firstUnit($property);

        self::book()->book($property->id(), ReserveMovementKind::Opening, self::day(), Money::fromCents(100000), null);
        self::book()->book($property->id(), ReserveMovementKind::Contribution, self::day(), Money::fromCents(25000), $unit->id());
        self::book()->book($property->id(), ReserveMovementKind::Interest, self::day(), Money::fromCents(1250), null);
        self::book()->book($property->id(), ReserveMovementKind::Withdrawal, self::day(), Money::fromCents(30000), null);

        self::assertSame(96250, self::balanceOf($property)->total()->cents());
    }

    /**
     * Eine beschlossene Sonderumlage steht auf dem Konto, sobald sie ankam.
     *
     * Nicht gebucht, sondern gelesen: der Beschluss stellt sie faellig, auf
     * dem Konto liegt aber nur, was gezahlt wurde. Wer eine Rate abhakt,
     * senkt damit den Bestand — ohne dass jemand eine Buchung nachzieht.
     */
    public function testAPaidLevyRaisesTheBalanceAndAMissedOneDoesNot(): void
    {
        self::bootKernel();
        $property = self::givenProperty(self::WEG, ManagementMode::Weg);
        $unit = self::firstUnit($property);

        self::book()->book($property->id(), ReserveMovementKind::Opening, self::day(), Money::fromCents(100000), null);
        $levy = new AdvancePayment(
            $unit->id(),
            AdvanceKind::ReserveLevy,
            2026,
            new DateTimeImmutable('2026-06-01'),
            Money::fromCents(50000),
        );
        self::payments()->saveAll([$levy]);

        self::assertSame(150000, self::balanceOf($property)->total()->cents(), 'Als bezahlt vorbelegt');

        $levy->missed(null);
        self::payments()->saveAll([$levy]);

        self::assertSame(100000, self::balanceOf($property)->total()->cents(), 'Nicht gezahlt, nicht auf dem Konto');

        $levy->missed(Money::fromCents(20000));
        self::payments()->saveAll([$levy]);

        self::assertSame(120000, self::balanceOf($property)->total()->cents(), 'Und eine Teilzahlung zaehlt zum Teil');
    }

    /**
     * Von Hand gebucht wird sie nicht.
     *
     * Sie stuende sonst zweimal da: einmal aus dem Beschluss und einmal aus
     * der Buchung — und niemand saehe an, welche der beiden die falsche ist.
     */
    public function testALevyCannotBeBookedByHand(): void
    {
        self::bootKernel();
        $property = self::givenProperty(self::WEG, ManagementMode::Weg);
        $unit = self::firstUnit($property);

        $this->expectException(LevyComesFromAResolution::class);

        self::book()->book(
            $property->id(),
            ReserveMovementKind::SpecialLevy,
            self::day(),
            Money::fromCents(50000),
            $unit->id(),
        );
    }

    /** Ohne WEG gibt es keine Gemeinschaftskasse. */
    public function testARentalPropertyKeepsNoReserve(): void
    {
        self::bootKernel();
        $property = self::givenProperty(self::RENTAL, ManagementMode::Rental);

        $this->expectException(ReserveOnlyForWeg::class);

        self::book()->book($property->id(), ReserveMovementKind::Opening, self::day(), Money::fromCents(1000), null);
    }

    /** Eine Zufuehrung ohne Einheit ist nicht nachvollziehbar. */
    public function testAContributionNeedsAUnit(): void
    {
        self::bootKernel();
        $property = self::givenProperty(self::WEG, ManagementMode::Weg);

        $this->expectException(ReserveNeedsAUnit::class);

        self::book()->book($property->id(), ReserveMovementKind::Contribution, self::day(), Money::fromCents(1000), null);
    }

    /** Eine Entnahme dagegen haengt am Objekt und nicht an einer Einheit. */
    public function testAWithdrawalKeepsNoUnit(): void
    {
        self::bootKernel();
        $property = self::givenProperty(self::WEG, ManagementMode::Weg);
        $unit = self::firstUnit($property);

        $movement = self::book()->book(
            $property->id(),
            ReserveMovementKind::Withdrawal,
            self::day(),
            Money::fromCents(1000),
            $unit->id(),
        );

        self::assertNull($movement->unitId(), 'Die Einheit wird nicht mitgeschleppt');
    }

    /** Verwahrentgelt gibt es — sonst ist ein Betrag positiv. */
    public function testOnlyInterestMayBeNegative(): void
    {
        self::bootKernel();
        $property = self::givenProperty(self::WEG, ManagementMode::Weg);

        $interest = self::book()->book(
            $property->id(),
            ReserveMovementKind::Interest,
            self::day(),
            Money::fromCents(-500),
            null,
        );
        self::assertSame(-500, $interest->amount()->cents());

        $this->expectException(InvalidArgumentException::class);
        self::book()->book($property->id(), ReserveMovementKind::Withdrawal, self::day(), Money::fromCents(-500), null);
    }

    /** Einen Anfangsbestand gibt es hoechstens einmal. */
    public function testASecondOpeningBalanceIsRefused(): void
    {
        self::bootKernel();
        $property = self::givenProperty(self::WEG, ManagementMode::Weg);
        self::book()->book($property->id(), ReserveMovementKind::Opening, self::day(), Money::fromCents(1000), null);

        $this->expectException(SecondOpeningBalance::class);

        self::book()->book($property->id(), ReserveMovementKind::Opening, self::day(), Money::fromCents(2000), null);
    }

    /**
     * Der Filter grenzt die Liste ein, nicht das Konto.
     *
     * Der Stand oben bleibt der volle: eine Auswahl beantwortet eine Frage
     * ueber einen Ausschnitt, sie aendert nicht, was auf dem Konto liegt.
     */
    public function testTheFilterNarrowsTheListAndNotTheBalance(): void
    {
        $client = self::signedInWith([FinancePermissions::VIEW]);
        $property = self::givenProperty(self::WEG, ManagementMode::Weg);

        self::book()->book($property->id(), ReserveMovementKind::Opening, self::day(), Money::fromCents(500000), null);
        self::book()->book(
            $property->id(),
            ReserveMovementKind::Withdrawal,
            new DateTimeImmutable('2025-06-01'),
            Money::fromCents(80000),
            null,
        );

        $client->request('GET', '/finanzen/ruecklage/'.$property->number().'?art=&jahr=2025&einheit=');
        $body = (string) $client->getResponse()->getContent();

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('4.200,00', $body, 'Der volle Stand steht oben');
        self::assertStringContainsString('-800,00', $body, 'Die gewählte Bewegung steht in der Liste');
        self::assertStringNotContainsString('5.000,00', $body, 'Die andere nicht');
    }

    /**
     * „Alle Jahre" schickt ein leeres Feld mit.
     *
     * Als Zahl gelesen beantwortete das die Seite mit 400 — wer nur nach der
     * Art filterte, bekam einen Fehler statt einer Liste. Was aus der
     * Adresszeile kommt, ist Eingabe.
     */
    public function testAnEmptyYearIsNoRestrictionAndNoError(): void
    {
        $client = self::signedInWith([FinancePermissions::VIEW]);
        $property = self::givenProperty(self::WEG, ManagementMode::Weg);

        self::book()->book($property->id(), ReserveMovementKind::Opening, self::day(), Money::fromCents(500000), null);

        $client->request('GET', '/finanzen/ruecklage/'.$property->number().'?art=opening&jahr=&einheit=');

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('5.000,00', (string) $client->getResponse()->getContent());
    }

    /**
     * Eine Einheit aus einem anderen Haus zahlt hier nicht ein.
     *
     * Die Oberflaeche bietet nur die eigenen an — aber ein abgeschicktes
     * Formular ist Eingabe und keine Zusicherung.
     */
    public function testAUnitFromAnotherPropertyCannotPayIn(): void
    {
        self::bootKernel();
        $weg = self::givenProperty(self::WEG, ManagementMode::Weg);
        $other = self::firstUnit(self::givenProperty(self::RENTAL, ManagementMode::Rental));

        $this->expectException(UnitBelongsElsewhere::class);

        self::book()->book(
            $weg->id(),
            ReserveMovementKind::Contribution,
            self::day(),
            Money::fromCents(25000),
            $other->id(),
        );
    }

    /** Eine erfundene Kennung ebenso wenig — und nicht als Datenbankfehler. */
    public function testAnInventedUnitIsRefusedBeforeTheForeignKey(): void
    {
        self::bootKernel();
        $property = self::givenProperty(self::WEG, ManagementMode::Weg);

        $this->expectException(UnitBelongsElsewhere::class);

        self::book()->book(
            $property->id(),
            ReserveMovementKind::Contribution,
            self::day(),
            Money::fromCents(25000),
            '00000000-0000-0000-0000-000000000000',
        );
    }

    /**
     * Zwei gleichzeitig gebuchte Anfangsbestaende — der zweite bekommt eine
     * Antwort.
     *
     * Die eigene Verbindung liest in einer Transaktion mit stabilem Blick;
     * die zweite bucht darin den Anfangsbestand. Die Pruefung sieht ihn
     * deshalb nicht mehr, der Teilindex schon.
     */
    public function testASecondOpeningInTheMeantimeIsRefusedReadably(): void
    {
        self::bootKernel();
        $property = self::givenProperty(self::WEG, ManagementMode::Weg);
        $connection = self::entityManager()->getConnection();
        $connection->beginTransaction();
        $connection->executeStatement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');

        try {
            self::assertSame([], self::movements()->forProperties([$property->id()]));

            $this->bookedByAnotherRequest($property->id());

            self::book()->book(
                $property->id(),
                ReserveMovementKind::Opening,
                self::day(),
                Money::fromCents(500000),
                null,
            );
            self::fail('Der zweite Anfangsbestand kam durch.');
        } catch (SecondOpeningBalance $problem) {
            self::assertNotSame('', $problem->getMessage(), 'Und zwar mit einer lesbaren Meldung');
        } finally {
            $connection->rollBack();
            $this->closeSecondConnection();
        }
    }

    /**
     * Eine gebuchte Bewegung wird storniert, nicht geloescht.
     *
     * Sie kann Grundlage einer Abrechnung sein. Sie bleibt lesbar stehen und
     * traegt nichts mehr bei — der Bestand ist weiter die Summe der
     * Bewegungen.
     */
    public function testAMovementIsReversedAndStaysReadable(): void
    {
        self::bootKernel();
        $property = self::givenProperty(self::WEG, ManagementMode::Weg);
        $movement = self::book()->book(
            $property->id(),
            ReserveMovementKind::Opening,
            self::day(),
            Money::fromCents(500000),
            null,
        );

        self::book()->reverse($movement, self::day());

        self::assertTrue($movement->isReversed());
        self::assertSame(0, self::balanceOf($property)->total()->cents(), 'Sie trägt nichts mehr bei');
        self::assertCount(1, self::balanceOf($property)->movements(), 'Und steht trotzdem da');
    }

    /** Ein zweites Storno waere eine Aenderung an der Geschichte. */
    public function testAMovementIsReversedOnlyOnce(): void
    {
        self::bootKernel();
        $property = self::givenProperty(self::WEG, ManagementMode::Weg);
        $movement = self::book()->book(
            $property->id(),
            ReserveMovementKind::Interest,
            self::day(),
            Money::fromCents(1250),
            null,
        );
        self::book()->reverse($movement, self::day());

        $this->expectException(AlreadyReversed::class);

        self::book()->reverse($movement, self::day());
    }

    /**
     * Ein stornierter Anfangsbestand laesst den richtigen zu.
     *
     * Sonst liesse sich eine Fehleingabe zwar zuruecknehmen, aber nie
     * berichtigen — und der Teilindex haelt genau das fest.
     */
    public function testAReversedOpeningMakesRoomForTheRightOne(): void
    {
        self::bootKernel();
        $property = self::givenProperty(self::WEG, ManagementMode::Weg);
        $wrong = self::book()->book(
            $property->id(),
            ReserveMovementKind::Opening,
            self::day(),
            Money::fromCents(100),
            null,
        );

        self::book()->reverse($wrong, self::day());
        self::book()->book(
            $property->id(),
            ReserveMovementKind::Opening,
            self::day(),
            Money::fromCents(500000),
            null,
        );

        self::assertSame(500000, self::balanceOf($property)->total()->cents());
    }

    /**
     * Ein Storno gilt nur fuer das Objekt, dessen Adresse man aufruft.
     *
     * Die Kennung der Buchung steht in der Adresszeile und ist damit
     * Eingabe: ohne Abgleich liesse sich mit einem gueltigen Token fuer das
     * eine Objekt eine Bewegung des anderen stornieren.
     */
    public function testAMovementOfAnotherPropertyCannotBeReversedFromHere(): void
    {
        $client = self::signedInWith([
            FinancePermissions::VIEW,
            FinancePermissions::EDIT,
            FinancePermissions::DELETE,
        ]);
        $weg = self::givenProperty(self::WEG, ManagementMode::Weg);
        $other = self::givenProperty(self::OTHER_WEG, ManagementMode::Weg);
        $movement = self::book()->book(
            $other->id(),
            ReserveMovementKind::Opening,
            self::day(),
            Money::fromCents(500000),
            null,
        );

        $crawler = $client->request('GET', '/finanzen/ruecklage/'.$weg->number().'?abschnitt=buchen');
        $token = (string) $crawler->filter('main input[name="_token"]')->first()->attr('value');

        $client->request(
            'POST',
            '/finanzen/ruecklage/'.$weg->number().'/buchen/'.$movement->id().'/storno',
            ['_token' => $token],
        );

        self::assertResponseStatusCodeSame(404);
        self::assertFalse($movement->isReversed(), 'Die fremde Buchung steht unverändert da');
    }

    /** Jeder Abschnitt eines Kontos zeichnet sich. */
    public function testEverySectionOfTheAccountRenders(): void
    {
        $client = self::signedInWith([FinancePermissions::VIEW, FinancePermissions::EDIT]);
        $property = self::givenProperty(self::WEG, ManagementMode::Weg);
        self::book()->book($property->id(), ReserveMovementKind::Opening, self::day(), Money::fromCents(500000), null);

        foreach (ReserveController::SECTIONS as $section) {
            $client->request('GET', '/finanzen/ruecklage/'.$property->number().'?abschnitt='.$section);
            self::assertResponseIsSuccessful('Abschnitt '.$section);
        }
    }

    protected static function testEmail(): string
    {
        return 'ruecklage@example.org';
    }

    /** Was im selben Augenblick eine andere Anfrage bucht. */
    private function bookedByAnotherRequest(string $propertyId): void
    {
        $this->secondConnection()->executeStatement(
            'INSERT INTO finance_reserve_movement (id, property_id, unit_id, kind, occurred_on, amount, note)
             VALUES (gen_random_uuid(), ?, NULL, ?, ?, 100000, ?)',
            [$propertyId, 'opening', '2026-03-01', ''],
        );
    }

    private static function firstUnit(Property $property): Unit
    {
        $units = $property->units();
        self::assertNotSame([], $units, 'Das Objekt hat keine Einheit.');

        return $units[0];
    }

    private static function day(): DateTimeImmutable
    {
        return new DateTimeImmutable('2026-03-01');
    }

    private static function balanceOf(Property $property): ReserveBalance
    {
        $balance = self::movements()->forProperties([$property->id()])[$property->id()] ?? null;
        self::assertNotNull($balance);

        return $balance;
    }

    private static function givenProperty(string $name, ManagementMode $mode): Property
    {
        $existing = self::propertyByName($name);

        if (null !== $existing) {
            return $existing;
        }

        $property = new Property(self::properties()->nextNumber(), $name, ManagementModes::of([$mode]));
        new Unit($property, 'WE 1');
        $property->managedAs($property->management()->activated());
        self::properties()->save($property);

        return $property;
    }

    private static function propertyByName(string $name): ?Property
    {
        $id = self::entityManager()->getConnection()->fetchOne('SELECT id FROM property WHERE name = ?', [$name]);

        return \is_string($id) ? self::properties()->byId($id) : null;
    }

    private static function book(): BookReserve
    {
        $book = self::getContainer()->get(BookReserve::class);
        self::assertInstanceOf(BookReserve::class, $book);

        return $book;
    }

    private static function payments(): AdvancePaymentRepository
    {
        $found = self::getContainer()->get(AdvancePaymentRepository::class);
        self::assertInstanceOf(AdvancePaymentRepository::class, $found);

        return $found;
    }

    private static function movements(): ReserveMovementRepository
    {
        $repository = self::getContainer()->get(ReserveMovementRepository::class);
        self::assertInstanceOf(ReserveMovementRepository::class, $repository);

        return $repository;
    }

    private static function properties(): PropertyRepository
    {
        $repository = self::getContainer()->get(PropertyRepository::class);
        self::assertInstanceOf(PropertyRepository::class, $repository);

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

        foreach ([self::WEG, self::RENTAL, self::OTHER_WEG] as $name) {
            $connection->executeStatement(
                'DELETE FROM finance_reserve_movement WHERE property_id IN (SELECT id FROM property WHERE name = ?)',
                [$name],
            );
            $connection->executeStatement(
                'DELETE FROM finance_advance_payment WHERE unit_id IN
                    (SELECT u.id FROM property_unit u JOIN property p ON p.id = u.property_id WHERE p.name = ?)',
                [$name],
            );
            $connection->executeStatement('DELETE FROM property WHERE name = ?', [$name]);
        }
    }
}
