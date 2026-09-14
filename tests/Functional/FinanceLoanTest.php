<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Module\Finance\Application\SaveLoan;
use App\Module\Finance\Application\SurveyLoans;
use App\Module\Finance\Contract\DecidedLoan;
use App\Module\Finance\Contract\DecidedLoans;
use App\Module\Finance\Contract\LoanDirectory;
use App\Module\Finance\Domain\EventBeforeTheLoan;
use App\Module\Finance\Domain\ExtraPaymentExceedsDebt;
use App\Module\Finance\Domain\FinancePermissions;
use App\Module\Finance\Domain\IncompleteLoanEvent;
use App\Module\Finance\Domain\Loan;
use App\Module\Finance\Domain\LoanEventKind;
use App\Module\Finance\Domain\LoanFilter;
use App\Module\Finance\Domain\LoanRepository;
use App\Module\Finance\Domain\LoanSchedule;
use App\Module\Finance\Domain\LoanTerms;
use App\Module\Property\Domain\ManagementMode;
use App\Module\Property\Domain\ManagementModes;
use App\Module\Property\Domain\Property;
use App\Module\Property\Domain\PropertyRepository;
use App\Module\Property\Domain\Unit;
use App\Shared\Money\LoanDoesNotAmortise;
use App\Shared\Money\Money;
use App\Shared\Ui\Page;
use App\Shared\Ui\Sort;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Die gefuehrten Darlehen.
 *
 * Die Zusicherung, an der alles haengt: **was gespeichert ist, ergibt einen
 * Tilgungsplan.** Eine Rate, die den Zins nicht deckt, kaeme sonst durch —
 * und jede Seite danach stuerzte beim Zeichnen ab, weil sie den Plan braucht.
 * Deshalb wird gerechnet, bevor gespeichert wird, und beim Scheitern bleibt
 * der alte Stand stehen.
 */
final class FinanceLoanTest extends WebTestCase
{
    use SignsIn;

    private const string PROPERTY = 'Darlehensobjekt Prüfung';

    protected function tearDown(): void
    {
        self::cleanUp();
        self::removeTestUser();

        parent::tearDown();
    }

    /** Ein angelegtes Darlehen rechnet seinen Plan — und die Restschuld faellt heraus. */
    public function testAStoredLoanYieldsItsSchedule(): void
    {
        self::bootKernel();
        $loan = self::givenLoan(12000000, 390, 85000, '2026-04-01');

        $open = self::directory()->outstandingAt(self::property()->id(), new DateTimeImmutable('2026-12-31'));

        self::assertCount(1, $open);
        self::assertSame(11580578, $open[0]->outstanding->cents(), 'Neun Raten sind gelaufen');
        self::assertSame('Dachsanierung', $loan->label());
    }

    /**
     * Eine Rate unter dem Zins wird abgelehnt — und aendert nichts.
     *
     * Der zweite Teil ist der wichtigere: ein halb geaendertes Darlehen waere
     * eines, dessen Plan nicht mehr aufgeht, und das faende erst die naechste
     * Seite heraus.
     */
    public function testARateBelowTheInterestLeavesTheLoanAsItWas(): void
    {
        self::bootKernel();
        $loan = self::givenLoan(12000000, 390, 85000, '2026-04-01');

        try {
            self::save()->agreeOn($loan, LoanTerms::withPayment(
                Money::fromCents(12000000),
                390,
                new DateTimeImmutable('2026-04-01'),
                Money::fromCents(10000),
            ));
            self::fail('Diese Rate tilgt nichts');
        } catch (LoanDoesNotAmortise) {
            self::assertSame(85000, $loan->terms()->payment()?->cents(), 'Die alte Rate steht noch');
        }
    }

    /** Mehr als die Restschuld laesst sich nicht tilgen. */
    public function testAnExtraPaymentBeyondTheDebtIsRefused(): void
    {
        self::bootKernel();
        $loan = self::givenLoan(12000000, 390, 85000, '2026-04-01');

        $this->expectException(ExtraPaymentExceedsDebt::class);
        self::save()->record(
            $loan,
            LoanEventKind::ExtraPayment,
            new DateTimeImmutable('2030-06-01'),
            Money::fromCents(20000000),
            null,
            '',
        );
    }

    /**
     * Ein Vorgang vor der ersten Rate wird abgelehnt — und nicht stumm verschluckt.
     *
     * Der Plan zaehlt in Monaten ab eins. Ein Tag davor ergibt Monat null,
     * den es nicht gibt: die Rechnung liesse den Vorgang fallen, waehrend er
     * im Verlauf steht und etwas anderes behauptet. Das ist der schlimmste
     * aller Faelle — eine Zahl, die dasteht und nicht wirkt.
     */
    public function testAnEntryBeforeTheFirstInstalmentIsRefused(): void
    {
        self::bootKernel();
        $loan = self::givenLoan(12000000, 390, 85000, '2026-04-01');

        $this->expectException(EventBeforeTheLoan::class);
        self::save()->record($loan, LoanEventKind::NewRate, new DateTimeImmutable('2026-03-31'), null, 1500, '');
    }

    /** Auch die Sondertilgung — und mit der Begruendung, die stimmt. */
    public function testAnExtraPaymentBeforeTheFirstInstalmentSaysWhy(): void
    {
        self::bootKernel();
        $loan = self::givenLoan(12000000, 390, 85000, '2026-04-01');

        try {
            self::save()->record(
                $loan,
                LoanEventKind::ExtraPayment,
                new DateTimeImmutable('2026-03-31'),
                Money::fromCents(500000),
                null,
                '',
            );
            self::fail('Vor der ersten Rate gibt es keinen Plan');
        } catch (EventBeforeTheLoan $problem) {
            self::assertSame(
                'finance.error.loan_event_too_early',
                $problem->getMessage(),
                'Nicht „mehr als die Restschuld" — das verschwiege den wahren Grund',
            );
        }
    }

    /**
     * Am Tag der ersten Rate wirkt sie — die andere Seite der Grenze.
     *
     * Ohne diesen Test koennte die Absage einen Tag zu weit greifen, und
     * niemand merkte es: eine Zinsaenderung, die abgelehnt wird, sieht aus
     * wie eine, die man falsch eingetragen hat.
     */
    public function testAnEntryOnTheFirstInstalmentChangesThePlan(): void
    {
        self::bootKernel();
        $loan = self::givenLoan(12000000, 390, 85000, '2026-04-01');
        $before = LoanSchedule::of($loan)->plan->totalInterest();

        // Sechs Prozent und nicht fünfzehn: fünfzehn deckte die Rate nicht
        // mehr, und dann prüfte der Test die andere Absage.
        self::save()->record($loan, LoanEventKind::NewRate, new DateTimeImmutable('2026-04-01'), null, 600, '');

        self::assertGreaterThan(
            $before->cents(),
            LoanSchedule::of($loan)->plan->totalInterest()->cents(),
            'Sechs Prozent kosten mehr als vier',
        );
    }

    /** Eine Zinsaenderung ohne Satz ist keine — ein leeres Feld ist keine Null. */
    public function testANewRateWithoutARateIsRefused(): void
    {
        self::bootKernel();
        $loan = self::givenLoan(12000000, 390, 85000, '2026-04-01');

        $this->expectException(IncompleteLoanEvent::class);
        self::save()->record($loan, LoanEventKind::NewRate, new DateTimeImmutable('2030-06-01'), null, null, '');
    }

    /** Was ein Jahr kostet, ist die Summe ueber alle Darlehen des Objekts. */
    public function testTheYearlyBurdenAddsUpEveryLoan(): void
    {
        self::bootKernel();
        self::givenLoan(12000000, 390, 85000, '2026-04-01');
        self::givenLoan(6000000, 275, 50000, '2026-04-01', 'Fassade');

        $burden = self::directory()->burdenIn(self::property()->id(), 2027);

        self::assertSame(
            12 * (85000 + 50000),
            $burden->interest->plus($burden->principal)->cents(),
            'Zwölf Raten je Darlehen',
        );
    }

    /**
     * Ein zweiter Beschluss derselben Massnahme legt kein zweites Darlehen an.
     *
     * Eine Berichtigung aendert das Blatt. Das gefuehrte Darlehen traegt
     * inzwischen die Bank und vielleicht schon eine Sondertilgung — es aus
     * einer Beschlussvorlage zu ueberschreiben waere verkehrt herum.
     */
    public function testADecidedLoanIsRecordedOnlyOnce(): void
    {
        self::bootKernel();
        $decided = new DecidedLoan(
            self::property()->id(),
            'BU-29101-2026-1',
            'Dachsanierung',
            Money::fromCents(12000000),
            390,
            new DateTimeImmutable('2026-04-01'),
            Money::fromCents(85000),
            null,
        );

        self::decidedLoans()->decided($decided);
        self::decidedLoans()->decided($decided);

        self::assertCount(1, self::loans()->forProperty(self::property()->id()));
        self::assertSame('BU-29101-2026-1', self::loans()->forProperty(self::property()->id())[0]->reference());
    }

    /**
     * Die Suche findet ein Darlehen an seiner Bank.
     *
     * Nummer, Bezeichnung, Bank — das sind die drei Angaben, die jemand im
     * Kopf hat. Ohne die Suche blaettert er durch die Darlehen fremder
     * Haeuser.
     */
    public function testTheSearchFindsALoanByItsBank(): void
    {
        self::bootKernel();
        self::givenLoan(12000000, 390, 85000, '2026-04-01');
        self::givenLoan(6000000, 275, 50000, '2026-04-01', 'Fassade', 'Volksbank Prüfung');

        $filter = LoanFilter::of(null, 'volksbank');
        $found = self::loans()->matching($filter, Page::of(1, self::loans()->countMatching($filter)), self::bySortedNumber());

        self::assertSame(1, self::loans()->countMatching($filter));
        self::assertCount(1, $found);
        self::assertSame('Fassade', $found[0]->label());
    }

    /** Ohne Einschraenkung stehen alle da. */
    public function testWithoutAFilterEveryLoanIsCounted(): void
    {
        self::bootKernel();
        self::givenLoan(12000000, 390, 85000, '2026-04-01');
        self::givenLoan(6000000, 275, 50000, '2026-04-01', 'Fassade');

        self::assertSame(2, self::loans()->countMatching(LoanFilter::none()));
    }

    /**
     * Die Uebersicht der Finanzen zaehlt alle Darlehen zusammen.
     *
     * Restschuld heute und die Belastung des gewaehlten Jahres — beides
     * gerechnet, beides je Objekt aufgeschluesselt. Einmal alle holen und
     * falten, statt je Zeile zu fragen.
     */
    public function testTheOverviewAddsUpEveryLoan(): void
    {
        self::bootKernel();
        self::givenLoan(12000000, 390, 85000, '2026-04-01');
        self::givenLoan(6000000, 275, 50000, '2026-04-01', 'Fassade');

        $overview = self::survey()->overview(2027);
        $open = self::directory()->outstandingAt(self::property()->id(), new DateTimeImmutable('today'));
        $single = Money::zero();

        foreach ($open as $loan) {
            $single = $single->plus($loan->outstanding);
        }

        self::assertSame($single->cents(), $overview['debt']->cents(), 'Dieselbe Restschuld wie einzeln gefragt');
        self::assertSame(12 * (85000 + 50000), $overview['burden']->total()->cents(), 'Zwölf Raten je Darlehen');
        self::assertSame([self::property()->id()], array_keys($overview['byProperty']), 'Ein Objekt, eine Summe');
        self::assertSame([$single->cents()], array_map(
            static fn (Money $open): int => $open->cents(),
            array_values($overview['byProperty']),
        ));
    }

    /** Wer nur lesen darf, bekommt die Liste — und sonst nichts. */
    public function testReadingDoesNotAllowRecording(): void
    {
        $client = self::signedInWith([FinancePermissions::VIEW]);
        self::givenLoan(12000000, 390, 85000, '2026-04-01');

        $client->request('GET', '/finanzen/darlehen');
        self::assertResponseIsSuccessful();

        $client->request('GET', '/finanzen/darlehen/neu');
        self::assertResponseStatusCodeSame(403);
    }

    protected static function testEmail(): string
    {
        return 'darlehen@example.org';
    }

    private static function bySortedNumber(): Sort
    {
        return Sort::by('nummer', Sort::DESCENDING);
    }

    private static function givenLoan(
        int $amount,
        int $rateBps,
        int $payment,
        string $startsOn,
        string $label = 'Dachsanierung',
        string $lender = 'Prüfbank',
    ): Loan {
        $loan = self::save()->forProperty(self::property()->id(), LoanTerms::withPayment(
            Money::fromCents($amount),
            $rateBps,
            new DateTimeImmutable($startsOn),
            Money::fromCents($payment),
        ));
        self::save()->describe($loan, $label, $lender, '');

        return $loan;
    }

    private static function property(): Property
    {
        $properties = self::getContainer()->get(PropertyRepository::class);
        self::assertInstanceOf(PropertyRepository::class, $properties);
        $id = self::entityManager()->getConnection()->fetchOne('SELECT id FROM property WHERE name = ?', [self::PROPERTY]);

        if (\is_string($id)) {
            $found = $properties->byId($id);
            self::assertInstanceOf(Property::class, $found);

            return $found;
        }

        $property = new Property($properties->nextNumber(), self::PROPERTY, ManagementModes::of([ManagementMode::Weg]));
        new Unit($property, 'WE 1');
        $property->managedAs($property->management()->activated());
        $properties->save($property);

        return $property;
    }

    private static function save(): SaveLoan
    {
        $save = self::getContainer()->get(SaveLoan::class);
        self::assertInstanceOf(SaveLoan::class, $save);

        return $save;
    }

    private static function loans(): LoanRepository
    {
        $loans = self::getContainer()->get(LoanRepository::class);
        self::assertInstanceOf(LoanRepository::class, $loans);

        return $loans;
    }

    private static function survey(): SurveyLoans
    {
        $survey = self::getContainer()->get(SurveyLoans::class);
        self::assertInstanceOf(SurveyLoans::class, $survey);

        return $survey;
    }

    private static function directory(): LoanDirectory
    {
        $directory = self::getContainer()->get(LoanDirectory::class);
        self::assertInstanceOf(LoanDirectory::class, $directory);

        return $directory;
    }

    private static function decidedLoans(): DecidedLoans
    {
        $decided = self::getContainer()->get(DecidedLoans::class);
        self::assertInstanceOf(DecidedLoans::class, $decided);

        return $decided;
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
            'DELETE FROM finance_loan WHERE property_id IN (SELECT id FROM property WHERE name = ?)',
            [self::PROPERTY],
        );
        $connection->executeStatement('DELETE FROM property WHERE name = ?', [self::PROPERTY]);
    }
}
