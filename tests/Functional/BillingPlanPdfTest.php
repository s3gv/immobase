<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Module\Billing\Application\PlanFromLastYear;
use App\Module\Billing\Application\ProposePlan;
use App\Module\Billing\Application\ReleasePlan;
use App\Module\Billing\Domain\AdvanceTerms;
use App\Module\Billing\Domain\BillingPermissions;
use App\Module\Billing\Domain\Plan;
use App\Module\Billing\Domain\PlanRepository;
use App\Module\Billing\Domain\Resolution;
use App\Module\Finance\Contract\Interval;
use App\Shared\Money\Money;
use DateTimeImmutable;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Der Einzelwirtschaftsplan als Brief.
 *
 * Was daraufsteht, verlangt § 28 Abs. 1 WEG: die voraussichtlichen Ausgaben
 * **nach Grund und Hoehe nachpruefbar**, die Zufuehrung zur Erhaltungsruecklage
 * eigens ausgewiesen, und der Vorschuss, ueber den beschlossen wird.
 *
 * „Nachpruefbar" heisst hier zweierlei: der Vorjahreswert neben dem Planwert,
 * und der Verteilerschluessel mit seiner Bezugsgroesse.
 */
final class BillingPlanPdfTest extends WebTestCase
{
    use BuildsABillableProperty;
    use ForgetsRateLimits;
    use ReadsPdfArchives;
    use SignsIn;

    protected function tearDown(): void
    {
        self::removeTheProperty();
        self::removeTestUser();

        parent::tearDown();
    }

    /** Je Einheit ein Schreiben, alle in einem Archiv. */
    public function testEveryUnitGetsItsOwnLetter(): void
    {
        $client = self::signedInWith([BillingPermissions::VIEW, BillingPermissions::EDIT]);
        $plan = self::aReleasedPlan();

        $client->request('GET', '/billing/wirtschaftsplaene/'.$plan->id().'/pdf');

        self::assertResponseIsSuccessful();
        self::assertSame('application/zip', $client->getResponse()->headers->get('Content-Type'));

        $bundle = (string) $client->getResponse()->getContent();

        self::assertStringStartsWith('PK', $bundle);
        self::assertSame(\count($plan->documents()), self::filesIn($bundle));
    }

    /** Alles, was das Gesetz verlangt, steht auf dem Blatt. */
    public function testTheLetterCarriesWhatTheLawAsksFor(): void
    {
        $client = self::signedInWith([BillingPermissions::VIEW, BillingPermissions::EDIT]);
        $plan = self::aReleasedPlan();

        $client->request('GET', '/billing/wirtschaftsplaene/'.$plan->id().'/pdf');

        $text = self::textIn((string) $client->getResponse()->getContent());

        self::assertStringContainsString('WP-'.self::PROPERTY_NUMBER.'/', $text, 'Die Art steht vor der Nummer');
        self::assertStringContainsString('Wirtschaftsplan 2027', $text, 'Der Betreff nennt das Planjahr');
        self::assertStringContainsString('Prüfsteuer', $text, 'Jede Position mit Namen');
        self::assertStringContainsString('Vorjahr 1.240,50', $text, 'Nach Grund und Höhe nachprüfbar');
        self::assertStringContainsString('Zuführung zur Erhaltungsrücklage', $text, 'Eigens ausgewiesen');
        self::assertStringContainsString('Ihr Jahresanteil', $text);
        self::assertStringContainsString('erstmals fällig am 01.01.2027', $text);
        self::assertStringContainsString('Beschlossen in der Eigentümerversammlung am 14.11.2026', $text);
        self::assertStringContainsString('einstimmig', $text, 'Das Ergebnis, wie es im Protokoll steht');
    }

    /**
     * Der Anteil steht mit Komma da.
     *
     * Gespeichert ist er `78.40` — maschinenlesbar. So gedruckt liest ein
     * deutscher Empfaenger `7840`, und daneben steht im selben Atemzug ein
     * Betrag mit Komma.
     */
    public function testTheLetterWritesNumbersTheWayTheLanguageDoes(): void
    {
        $client = self::signedInWith([BillingPermissions::VIEW, BillingPermissions::EDIT]);
        $plan = self::aReleasedPlan();

        $client->request('GET', '/billing/wirtschaftsplaene/'.$plan->id().'/pdf');

        $text = self::textIn((string) $client->getResponse()->getContent());

        self::assertStringContainsString('78,40', $text);
        self::assertStringNotContainsString('78.40', $text);
    }

    /**
     * Ein Verbrauchsschluessel verteilt nach dem Vorjahr — und sagt es.
     *
     * Fuer ein Jahr, das noch nicht stattgefunden hat, gibt es keinen
     * Verbrauch. „Nach erfasstem Verbrauch" auf dem Blatt behauptete eine
     * Messung, die es nicht gibt.
     */
    public function testAMeteredKeyNamesTheYearItsFiguresComeFrom(): void
    {
        $client = self::signedInWith([BillingPermissions::VIEW, BillingPermissions::EDIT]);
        $plan = self::aReleasedPlan();

        $client->request('GET', '/billing/wirtschaftsplaene/'.$plan->id().'/pdf');

        $text = self::textIn((string) $client->getResponse()->getContent());

        self::assertStringContainsString('nach Miteigentumsanteilen', $text);
        self::assertStringNotContainsString('nach erfasstem Verbrauch', $text, 'Ein Plan misst nichts');
    }

    /**
     * Die Beschlussvorlage sagt, dass sie eine ist.
     *
     * Ein Blatt, das vor der Versammlung herausgeht und aussieht wie ein
     * Beschluss, waere eine Behauptung ueber etwas, das noch nicht
     * stattgefunden hat.
     */
    public function testTheProposalSaysThatNothingIsResolvedYet(): void
    {
        $client = self::signedInWith([BillingPermissions::VIEW, BillingPermissions::EDIT]);
        $plan = self::aProposedPlan();

        $client->request('GET', '/billing/wirtschaftsplaene/'.$plan->id().'/vorlage');

        self::assertResponseIsSuccessful();
        $text = self::textIn((string) $client->getResponse()->getContent());

        self::assertStringContainsString('Beschlussvorlage', $text);
        self::assertStringContainsString('wird in der Eigentümerversammlung beschlossen', $text);
        self::assertStringContainsString('Ihr Jahresanteil', $text, 'Gerechnet wird dasselbe');
        self::assertStringNotContainsString('Beschlossen in der', $text, 'Beschlossen ist noch nichts');
    }

    /** Aus einem Entwurf entsteht schon eine Vorlage — aber kein Beschluss. */
    public function testADraftHasAProposalButNoResolvedLetter(): void
    {
        $client = self::signedInWith([BillingPermissions::VIEW, BillingPermissions::EDIT]);
        $plan = self::aProposedPlan();

        $client->request('GET', '/billing/wirtschaftsplaene/'.$plan->id().'/pdf');

        self::assertResponseStatusCodeSame(404);
    }

    /** Und umgekehrt: was beschlossen ist, geht nicht mehr als Vorlage heraus. */
    public function testAResolvedPlanHasNoProposal(): void
    {
        $client = self::signedInWith([BillingPermissions::VIEW, BillingPermissions::EDIT]);
        $plan = self::aReleasedPlan();

        $client->request('GET', '/billing/wirtschaftsplaene/'.$plan->id().'/vorlage');

        self::assertResponseStatusCodeSame(404);
    }

    /** Ohne Recht kein Archiv. */
    public function testItNeedsThePermission(): void
    {
        $client = self::signedInWith([]);
        self::buildTheProperty();

        $client->request('GET', '/billing/wirtschaftsplaene/'.self::aDraft()->id().'/pdf');

        self::assertResponseStatusCodeSame(403);
    }

    protected static function testEmail(): string
    {
        return 'planarchiv@example.org';
    }

    private static function aReleasedPlan(): Plan
    {
        $plan = self::aProposedPlan();
        $plan->decide(Resolution::of(new DateTimeImmutable('2026-11-14'), 'einstimmig', '2026/04'));
        self::plans()->save($plan);

        $release = self::getContainer()->get(ReleasePlan::class);
        self::assertInstanceOf(ReleasePlan::class, $release);
        $release->release($plan, new DateTimeImmutable('2026-11-20'), despiteDrift: false);

        return $plan;
    }

    private static function aProposedPlan(): Plan
    {
        self::buildTheProperty();
        self::alsoCostsByMea(Money::fromCents(300000));
        self::alsoPaidIntoTheReserve(Money::fromCents(60000));

        $plan = self::aDraft();
        $plan->describe('Wirtschaftsplan 2027');

        $fill = self::getContainer()->get(PlanFromLastYear::class);
        self::assertInstanceOf(PlanFromLastYear::class, $fill);
        $fill->fill($plan);

        $plan->payOn(AdvanceTerms::of(Interval::Monthly, new DateTimeImmutable('2027-01-01')));
        self::plans()->save($plan);

        $propose = self::getContainer()->get(ProposePlan::class);
        self::assertInstanceOf(ProposePlan::class, $propose);
        $propose->propose($plan, new DateTimeImmutable('2026-10-30'));

        return $plan;
    }

    private static function aDraft(): Plan
    {
        $plans = self::plans();
        $plan = new Plan($plans->nextNumber(), self::propertyId(), self::PROPERTY_NUMBER, self::aFiscalYear(2027));
        $plans->save($plan);

        return $plan;
    }

    private static function plans(): PlanRepository
    {
        $found = self::getContainer()->get(PlanRepository::class);
        self::assertInstanceOf(PlanRepository::class, $found);

        return $found;
    }
}
