<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Module\Billing\Application\ReleaseBudget;
use App\Module\Billing\Application\StartBudget;
use App\Module\Billing\Domain\BillingPermissions;
use App\Module\Billing\Domain\Budget;
use App\Module\Billing\Domain\BudgetPosition;
use App\Module\Billing\Domain\BudgetRepository;
use App\Module\Billing\Domain\Funding;
use App\Module\Billing\Domain\MeasureKind;
use App\Module\Billing\Domain\Resolution;
use App\Module\Finance\Contract\Interval;
use App\Shared\Money\Money;
use DateTimeImmutable;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Der Budgetplan als Brief.
 *
 * Was daraufstehen muss, verlangt das Beschlussrecht: bei einer Sonderumlage
 * Zweck, Betrag, Verteilerschluessel und Faelligkeit — fehlt eines davon, ist
 * der Beschluss anfechtbar. Dazu die Norm, nach der jemand zahlt, und bei
 * einem Darlehen der Hinweis auf das Nachschussrisiko.
 */
final class BillingBudgetPdfTest extends WebTestCase
{
    use BuildsABillableProperty;
    use ForgetsRateLimits;
    use ReadsPdfArchives;
    use SignsIn;

    private const int FIRST_YEAR = 2027;

    protected function tearDown(): void
    {
        self::removeTheProperty();
        self::removeTestUser();

        parent::tearDown();
    }

    /** Je tragender Einheit ein Schreiben, alle in einem Archiv. */
    public function testEveryBearingUnitGetsItsOwnLetter(): void
    {
        $client = self::signedInWith([BillingPermissions::VIEW]);
        $budget = self::aDecidedBudget();

        $client->request('GET', '/billing/budgetplaene/'.$budget->id().'/pdf');

        self::assertResponseIsSuccessful();
        self::assertSame('application/zip', $client->getResponse()->headers->get('Content-Type'));
        self::assertSame(2, self::filesIn((string) $client->getResponse()->getContent()));
    }

    /** Die vier Pflichtangaben der Sonderumlage stehen auf dem Blatt. */
    public function testTheLetterCarriesWhatAResolutionNeeds(): void
    {
        $client = self::signedInWith([BillingPermissions::VIEW]);
        $budget = self::aDecidedBudget();

        $client->request('GET', '/billing/budgetplaene/'.$budget->id().'/pdf');
        $text = self::textIn((string) $client->getResponse()->getContent());

        self::assertStringContainsString('Photovoltaikanlage', $text, 'Der Zweck');
        self::assertStringContainsString('12.000,00', $text, 'Der Betrag');
        self::assertStringContainsString('Sonderumlage', $text);
        self::assertStringContainsString('01.03.2027', $text, 'Die Fälligkeit');
        self::assertStringContainsString('2. Rate', $text, 'Und jede weitere');

        // Der Brief teilt die Raten nicht selbst auf, sondern fragt dieselbe
        // Stelle wie die Vorlage. Stuende hier eine andere Aufteilung, traege
        // die **erste** Rate den Rest — darum der Blick hinter die letzte.
        $last = substr($text, (int) strpos($text, '3. Rate'));
        self::assertStringContainsString('2.222,23', $last, 'Der Rest liegt auf der letzten Rate');
    }

    /**
     * Auf dem Blatt steht, **warum** jemand zahlt.
     *
     * Eine Zahl ohne die Norm, auf der sie steht, ist eine Zumutung.
     */
    public function testTheLetterNamesTheRuleUnderWhichSomeonePays(): void
    {
        $client = self::signedInWith([BillingPermissions::VIEW]);
        $budget = self::aDecidedBudget();

        $client->request('GET', '/billing/budgetplaene/'.$budget->id().'/pdf');
        $text = self::textIn((string) $client->getResponse()->getContent());

        self::assertStringContainsString('§ 21 Abs. 2 Nr. 1 WEG', $text);
        // Und der Beschluss steht mit seinen Teilen darunter — ein fehlender
        // Uebersetzungsschluessel stuende hier als „billing.budget.pdf.…".
        self::assertStringContainsString('Beschlossen in der Eigentümerversammlung am 14.11.2026', $text);
        self::assertStringContainsString('Beschluss 2026/07', $text);
        self::assertStringNotContainsString('billing.budget', $text, 'Kein Schlüssel auf dem Blatt');
    }

    /** Bei einem Darlehen steht der Hinweis auf das Nachschussrisiko darauf. */
    public function testALoanCarriesTheNoteAboutAdditionalContributions(): void
    {
        $client = self::signedInWith([BillingPermissions::VIEW]);
        $budget = self::aDecidedBudget(Funding::none()->byLoan(
            Money::fromCents(1200000),
            420,
            null,
            120,
        ));

        $client->request('GET', '/billing/budgetplaene/'.$budget->id().'/pdf');
        $text = self::textIn((string) $client->getResponse()->getContent());

        self::assertStringContainsString('Nachschuss', $text);
        self::assertStringContainsString('Darlehen', $text);
    }

    /** Dasselbe Archiv, zweimal geholt — Byte fuer Byte dasselbe. */
    public function testTheSameBudgetProducesTheSameBytes(): void
    {
        $client = self::signedInWith([BillingPermissions::VIEW]);
        $budget = self::aDecidedBudget();

        $client->request('GET', '/billing/budgetplaene/'.$budget->id().'/pdf');
        $first = (string) $client->getResponse()->getContent();

        $client->request('GET', '/billing/budgetplaene/'.$budget->id().'/pdf');

        self::assertSame($first, (string) $client->getResponse()->getContent());
    }

    /** Aus einem Entwurf entsteht keine beschlossene Fassung. */
    public function testADraftHasNoPdf(): void
    {
        $client = self::signedInWith([BillingPermissions::VIEW]);
        self::buildTheProperty();
        $budget = self::aBudget();

        $client->request('GET', '/billing/budgetplaene/'.$budget->id().'/pdf');

        self::assertResponseStatusCodeSame(404);
    }

    protected static function testEmail(): string
    {
        return 'budgetpdf@example.org';
    }

    private static function aDecidedBudget(?Funding $funding = null): Budget
    {
        self::buildTheProperty();
        $budget = self::aBudget();
        $budget->fund($funding ?? Funding::none()->byLevy(
            Money::fromCents(1200000),
            new DateTimeImmutable('2027-03-01'),
            3,
            Interval::Quarterly,
        ));
        $budget->decide(Resolution::of(new DateTimeImmutable('2026-11-14'), 'einstimmig', '2026/07'), 3, 3);
        self::budgets()->save($budget);
        self::budgets()->replaceApprovals($budget->id(), self::unitIds());

        $release = self::getContainer()->get(ReleaseBudget::class);
        self::assertInstanceOf(ReleaseBudget::class, $release);
        $release->release($budget, new DateTimeImmutable('2026-11-15'));

        return $budget;
    }

    private static function aBudget(): Budget
    {
        $start = self::getContainer()->get(StartBudget::class);
        self::assertInstanceOf(StartBudget::class, $start);

        $budget = $start->forProperty(
            self::PROPERTY_NUMBER,
            self::FIRST_YEAR,
            'Photovoltaikanlage Dach',
            MeasureKind::Structural->value,
        );
        self::assertInstanceOf(Budget::class, $budget);

        $position = $budget->positions()[0] ?? null;
        self::assertInstanceOf(BudgetPosition::class, $position);
        $position->describe('Angebot Solarbau', self::FIRST_YEAR, Money::fromCents(1200000), 'Angebot vom 14.03.');
        self::budgets()->save($budget);

        return $budget;
    }

    private static function budgets(): BudgetRepository
    {
        $budgets = self::getContainer()->get(BudgetRepository::class);
        self::assertInstanceOf(BudgetRepository::class, $budgets);

        return $budgets;
    }
}
