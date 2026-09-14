<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Module\Billing\Domain\BillingPermissions;
use App\Module\Billing\Domain\Budget;
use App\Module\Billing\Domain\BudgetFilter;
use App\Module\Billing\Domain\BudgetPosition;
use App\Module\Billing\Domain\BudgetRepository;
use App\Module\Billing\Domain\CostBearing;
use App\Module\Billing\Domain\ResolutionStatus;
use App\Shared\Ui\Page;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;

/**
 * Der Ablauf, so wie ihn jemand durchklickt.
 *
 * Fuenf Schritte bis zum Beschluss — und der Beschluss ist hier mehr als ein
 * Datum: aus den Stimmen und den Haekchen folgt, wer zahlt.
 */
final class BillingBudgetFlowTest extends WebTestCase
{
    use BuildsABillableProperty;
    use ForgetsRateLimits;
    use SignsIn;

    private const int FIRST_YEAR = 2027;

    protected function tearDown(): void
    {
        self::removeTheProperty();
        self::removeTestUser();

        parent::tearDown();
    }

    /** Vom leeren Formular bis zum Beschluss. */
    public function testTheWholeFlowFromTheFirstStepToTheResolution(): void
    {
        $client = self::signedInWith([BillingPermissions::VIEW, BillingPermissions::EDIT]);
        self::buildTheProperty();
        $budget = self::started($client, 'structural');

        self::assertSame('Photovoltaikanlage Dach', $budget->measure()->label());
        self::assertCount(1, $budget->positions(), 'Eine leere Position steht schon da');

        self::costs($client, $budget, '12.000,00');
        self::funding($client, $budget);

        $sharing = $client->request('GET', self::stepUrl($budget, 'verteilung'));
        self::assertStringContainsString('Annahme', $sharing->filter('.ib-note')->text());

        $client->submitForm('Als Beschlussvorlage herausgeben');
        self::assertTrue(self::reloaded($budget)->stage()->wasProposed());

        self::decide($client, $budget, cast: 3, for: 3, agreed: self::unitIds());

        $client->request('GET', self::stepUrl($budget, 'beschluss'));
        $client->submitForm('Beschließen');
        self::assertResponseRedirects('/billing/budgetplaene/'.$budget->id());

        $decided = self::reloaded($budget);
        self::assertSame(ResolutionStatus::Released, $decided->stage()->status());
        self::assertSame(CostBearing::QualifiedMajority, $decided->verdict()->bears());
        self::assertCount(2, $decided->documents(), 'Je tragender Einheit ein Schreiben');

        // Wer wann wie viel zahlt, muss dastehen: ein Betrag ohne seinen Tag
        // ist keine Zahlungsaufforderung, und drei Raten ohne ihre Betraege
        // lassen den Empfaenger rechnen.
        $shown = $client->request('GET', '/billing/budgetplaene/'.$budget->id());
        $tables = $shown->filter('table');
        $perUnit = $tables->eq($tables->count() - 2)->text();
        $dates = $tables->last()->text();

        // 2.222,22 + 2.222,22 + 2.222,23 — die letzte Rate traegt den Rest.
        self::assertStringContainsString('2.222,22', $perUnit, 'Die Rate der ersten Einheit');
        self::assertStringContainsString('2.222,23', $perUnit, 'Ihre letzte Rate daneben');
        self::assertStringContainsString('6.666,67', $perUnit, 'Und was zusammenkommt');

        self::assertStringContainsString('01.03.2027', $dates);
        self::assertStringContainsString('01.09.2027', $dates);
        self::assertStringContainsString('3.999,99', $dates, 'Was am ersten Termin insgesamt faellig ist');

        $list = $client->request('GET', '/billing/budgetplaene');
        self::assertStringContainsString('Beschlossen', $list->filter('tbody')->text());
    }

    /**
     * Ohne die doppelt qualifizierte Mehrheit traegt nur, wer zugestimmt hat.
     *
     * Und das Schreiben geht auch nur an ihn: wer nicht zahlt, bekommt keine
     * Zahlungsaufforderung.
     */
    public function testWithoutTheMajorityOnlyTheConsentingUnitGetsALetter(): void
    {
        $client = self::signedInWith([BillingPermissions::VIEW, BillingPermissions::EDIT]);
        self::buildTheProperty();
        $budget = self::started($client, 'structural');

        self::costs($client, $budget, '12.000,00');
        self::funding($client, $budget);
        self::decide($client, $budget, cast: 2, for: 1, agreed: [self::unitId(0)]);

        $client->request('GET', self::stepUrl($budget, 'beschluss'));
        $client->submitForm('Beschließen');

        $decided = self::reloaded($budget);
        self::assertSame(CostBearing::OnlyThoseWhoAgreed, $decided->verdict()->bears());
        self::assertCount(1, $decided->documents());
    }

    /**
     * Wer die Finanzierung aendert, nimmt die Vorlage zurueck.
     *
     * Das Abstimmungsergebnis dagegen nicht: es kommt nach der Vorlage, und es
     * einzutragen darf sie nicht entwerten.
     */
    public function testChangingTheFundingWithdrawsTheProposalAndVotingDoesNot(): void
    {
        $client = self::signedInWith([BillingPermissions::VIEW, BillingPermissions::EDIT]);
        self::buildTheProperty();
        $budget = self::started($client);
        self::costs($client, $budget, '12.000,00');
        self::funding($client, $budget);

        $client->request('GET', self::stepUrl($budget, 'verteilung'));
        $client->submitForm('Als Beschlussvorlage herausgeben');
        self::assertTrue(self::reloaded($budget)->stage()->wasProposed());

        self::decide($client, $budget, cast: 3, for: 3, agreed: self::unitIds());
        self::assertTrue(self::reloaded($budget)->stage()->wasProposed(), 'Abstimmen ändert nichts am Inhalt');

        self::funding($client, self::reloaded($budget), levy: '6.000,00', reserve: '6.000,00');

        self::assertFalse(self::reloaded($budget)->stage()->wasProposed(), 'Die Finanzierung schon');
    }

    /**
     * Zwoelf Raten machen die Seite laenger und nicht breiter.
     *
     * Je Faelligkeit eine Spalte liest sich bei drei Raten gut und bei zwoelf
     * gar nicht mehr — das Blatt scrollt dann seitwaerts, und genau das soll
     * im ganzen Modul nicht passieren. Darum nennt die Einheitentabelle die
     * Rate, und die Termine stehen untereinander.
     */
    public function testManyInstalmentsDoNotWidenTheTable(): void
    {
        $client = self::signedInWith([BillingPermissions::VIEW, BillingPermissions::EDIT]);
        self::buildTheProperty();
        $budget = self::started($client);
        self::costs($client, $budget, '12.000,00');

        $client->request('GET', self::stepUrl($budget, 'finanzierung'));
        $client->submitForm('Weiter', [
            'reserve' => '',
            'levy' => '12.000,00',
            'levyDueOn' => '2027-03-01',
            'levyParts' => '12',
            'levyInterval' => 'monthly',
        ]);

        $crawler = $client->request('GET', self::stepUrl($budget, 'verteilung'));
        $columns = $crawler->filter('table thead tr')->each(
            static fn (Crawler $row): int => $row->filter('th')->count(),
        );

        self::assertNotEmpty($columns, 'Es gibt Tabellen');
        self::assertLessThanOrEqual(5, max($columns), 'Keine Spalte je Fälligkeit');

        $schedule = $crawler->filter('table')->last();
        self::assertCount(12, $schedule->filter('tbody tr'), 'Zwölf Termine untereinander');
        self::assertStringContainsString('01.02.2028', $schedule->text(), 'Bis zur letzten Rate');
    }

    /** Eine Deckungsluecke laesst den Knopf gar nicht erst erscheinen. */
    public function testAGapHidesTheResolveButton(): void
    {
        $client = self::signedInWith([BillingPermissions::VIEW, BillingPermissions::EDIT]);
        self::buildTheProperty();
        $budget = self::started($client);
        self::costs($client, $budget, '12.000,00');

        $crawler = $client->request('GET', self::stepUrl($budget, 'beschluss'));

        self::assertCount(1, $crawler->filter('.ib-note--warning'), 'Der Hinweis steht da');
        self::assertCount(0, $crawler->filter('form[action$="/beschliessen"]'), 'Und kein Knopf');
    }

    /**
     * Eine Rate, die den Zins nicht deckt, wird abgewiesen.
     *
     * Und zwar mit einem Satz, den man versteht — nicht mit einer Rechnung,
     * die nie endet.
     */
    public function testARateBelowTheInterestIsRefused(): void
    {
        $client = self::signedInWith([BillingPermissions::VIEW, BillingPermissions::EDIT]);
        self::buildTheProperty();
        $budget = self::started($client);
        self::costs($client, $budget, '12.000,00');

        $client->request('GET', self::stepUrl($budget, 'finanzierung'));
        $client->submitForm('Weiter', [
            'loan' => '12.000,00',
            'loanRate' => '4,20',
            'loanPayment' => '10,00',
        ]);

        // Ein Fehler bleibt auf dem Schritt stehen: die Eingabe soll dort
        // korrigiert werden, wo sie gemacht wurde.
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.ib-flash', 'deckt nicht einmal die Zinsen');
        self::assertSame(0, self::reloaded($budget)->funding()->loan()->cents(), 'Nichts gespeichert');
    }

    /** Ein Entwurf ist keine Post — er wird auch nicht angesehen. */
    public function testADraftCannotBeViewed(): void
    {
        $client = self::signedInWith([BillingPermissions::VIEW, BillingPermissions::EDIT]);
        self::buildTheProperty();
        $budget = self::started($client);

        $client->request('GET', '/billing/budgetplaene/'.$budget->id());

        self::assertResponseStatusCodeSame(404);
    }

    /** Ohne Bearbeitungsrecht gibt es den Ablauf nicht. */
    public function testTheFlowNeedsTheEditPermission(): void
    {
        $client = self::signedInWith([BillingPermissions::VIEW]);
        self::buildTheProperty();

        $client->request('GET', '/billing/budgetplaene/neu');

        self::assertResponseStatusCodeSame(403);
    }

    /** Ein Entwurf laesst sich loeschen. */
    public function testADraftCanBeDeleted(): void
    {
        $client = self::signedInWith([BillingPermissions::VIEW, BillingPermissions::EDIT]);
        self::buildTheProperty();
        $budget = self::started($client);

        $crawler = $client->request('GET', self::stepUrl($budget, 'kosten'));
        $client->request('POST', '/billing/budgetplaene/'.$budget->id().'/loeschen', [
            '_token' => self::tokenOn($crawler),
        ]);

        self::assertResponseRedirects('/billing/budgetplaene');
        self::assertSame(0, self::budgets()->countMatching(BudgetFilter::none()));
    }

    protected static function testEmail(): string
    {
        return 'budgetklick@example.org';
    }

    private static function started(KernelBrowser $client, string $kind = 'maintenance'): Budget
    {
        $client->request('GET', '/billing/budgetplaene/neu');
        $client->submitForm('Weiter', [
            'property' => (string) self::PROPERTY_NUMBER,
            'firstYear' => (string) self::FIRST_YEAR,
            'label' => 'Photovoltaikanlage Dach',
            'kind' => $kind,
        ]);

        $budgets = self::budgets()->matching(BudgetFilter::none(), Page::of(1, 10));
        self::assertCount(1, $budgets);

        return $budgets[0];
    }

    /** Die erste Position ausfuellen. */
    private static function costs(KernelBrowser $client, Budget $budget, string $amount): void
    {
        $position = self::reloaded($budget)->positions()[0] ?? null;
        self::assertInstanceOf(BudgetPosition::class, $position);

        $client->request('GET', self::stepUrl($budget, 'kosten'));
        $client->submitForm('Weiter', [
            'rows['.$position->id().'][label]' => 'Angebot Solarbau',
            'rows['.$position->id().'][amount]' => $amount,
        ]);
        self::assertResponseRedirects(self::stepUrl($budget, 'finanzierung'));
    }

    private static function funding(
        KernelBrowser $client,
        Budget $budget,
        string $levy = '12.000,00',
        string $reserve = '',
    ): void {
        $client->request('GET', self::stepUrl($budget, 'finanzierung'));
        $client->submitForm('Weiter', [
            'reserve' => $reserve,
            'levy' => $levy,
            'levyDueOn' => '2027-03-01',
            'levyParts' => '3',
            'levyInterval' => 'quarterly',
        ]);
        self::assertResponseRedirects(self::stepUrl($budget, 'verteilung'));
    }

    /**
     * @param list<string> $agreed
     */
    private static function decide(KernelBrowser $client, Budget $budget, int $cast, int $for, array $agreed): void
    {
        $crawler = $client->request('GET', self::stepUrl($budget, 'beschluss'));
        $values = [
            '_token' => self::tokenOn($crawler),
            'decidedOn' => '2026-11-14',
            'outcome' => 'mit großer Mehrheit',
            'decisionNumber' => '2026/07',
            'votesCast' => (string) $cast,
            'votesFor' => (string) $for,
            'agreed' => $agreed,
            'direction' => 'forward',
        ];

        $client->request('POST', self::stepUrl($budget, 'beschluss'), $values);
        self::assertResponseRedirects();
    }

    private static function stepUrl(Budget $budget, string $step): string
    {
        return '/billing/budgetplaene/'.$budget->id().'/bearbeiten/'.$step;
    }

    private static function reloaded(Budget $budget): Budget
    {
        $found = self::budgets()->byId($budget->id());
        self::assertInstanceOf(Budget::class, $found);

        return $found;
    }

    private static function tokenOn(Crawler $crawler): string
    {
        $token = $crawler->filter('main input[name="_token"]')->first();

        return 0 === $token->count() ? '' : (string) $token->attr('value');
    }

    /** Die wievielte Einheit des Objekts — sie gibt es, die Zusicherung steht hier. */
    private static function unitId(int $at): string
    {
        $id = self::unitIds()[$at] ?? null;
        self::assertIsString($id);

        return $id;
    }

    private static function budgets(): BudgetRepository
    {
        $budgets = self::getContainer()->get(BudgetRepository::class);
        self::assertInstanceOf(BudgetRepository::class, $budgets);

        return $budgets;
    }
}
