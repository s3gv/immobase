<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Module\Billing\Application\IssueRentInvoice;
use App\Module\Billing\Application\ReviseRentInvoice;
use App\Module\Billing\Application\StartRentInvoice;
use App\Module\Billing\Domain\BillingPermissions;
use App\Module\Billing\Domain\RentInvoice;
use App\Module\Billing\Domain\RentInvoiceFilter;
use App\Module\Billing\Domain\RentInvoiceRepository;
use App\Module\Party\Domain\PartyFilter;
use App\Module\Party\Domain\PartyRepository;
use App\Module\Party\Domain\TaxId;
use App\Shared\Ui\Page;
use DateTimeImmutable;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;

/**
 * Der Ablauf, so wie ihn jemand durchklickt.
 *
 * Vier Schritte, und nur der erste hat Eingabefelder. Die drei anderen
 * zeigen, was anderswo steht — die Miete im Mietverhaeltnis, der Vermieter
 * an der Einheit, das Konto am Objekt. Wer dort etwas aendern will, geht
 * dorthin.
 */
final class RentInvoiceFlowTest extends WebTestCase
{
    use BuildsALetProperty;
    use ForgetsRateLimits;
    use SignsIn;

    protected function tearDown(): void
    {
        self::removeTheLetProperty();
        self::removeTestUser();

        parent::tearDown();
    }

    /** Vom leeren Formular bis zur ausgestellten Rechnung. */
    public function testTheWholeFlowFromTheFirstStepToTheIssue(): void
    {
        $client = self::signedInWith([BillingPermissions::VIEW, BillingPermissions::EDIT]);
        self::buildTheLetProperty();

        $invoice = self::started($client);
        self::assertSame('Dauermietrechnung ab 2026', $invoice->label());
        self::assertTrue($invoice->release()->isDraft());

        // Die Beträge stehen da, ohne dass jemand sie eingetragen hätte.
        $amounts = $client->request('GET', self::stepUrl($invoice, 'betraege'));
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('1.800,00', $amounts->filter('.ib-content')->text());
        self::assertStringContainsString('2.737,00', $amounts->filter('.ib-content')->text());

        $parties = $client->request('GET', self::stepUrl($invoice, 'empfaenger'));
        self::assertStringContainsString('Ladenbetrieb GmbH', $parties->filter('.ib-content')->text());
        self::assertStringContainsString('133/5711/0815', $parties->filter('.ib-content')->text());

        $issue = $client->request('GET', self::stepUrl($invoice, 'ausstellung'));
        self::assertCount(0, $issue->filter('.ib-note--warning'), 'Nichts fehlt');
        self::assertStringContainsString('DE02 1203 0000 0000 2020 51', $issue->filter('.ib-preview')->text());

        $client->submitForm('Ausstellen');
        self::assertResponseRedirects('/billing/dauermietrechnungen/'.$invoice->id());

        self::assertFalse(self::reloaded($invoice)->release()->isDraft());

        // Dieselbe Seite wie eben im Ablauf, nur ohne Knöpfe — und mit dem
        // Ausstellungsdatum, das es vorher nicht gab.
        $shown = $client->followRedirect();
        self::assertStringContainsString('2.737,00', $shown->filter('.ib-preview')->text());
        self::assertStringNotContainsString(
            'Ausgestellt am—',
            str_replace(' ', '', $shown->filter('.ib-preview')->text()),
        );

        $list = $client->request('GET', '/billing/dauermietrechnungen');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Ausgestellt', $list->filter('tbody')->text());
    }

    /**
     * Fehlt eine Pflichtangabe, ist der Knopf gar nicht erst da.
     *
     * Und der Hinweis sagt, welche — sonst sucht jemand in vier Schritten
     * nach etwas, das in einem fuenften Modul steht.
     */
    public function testAMissingTaxNumberHidesTheIssueButton(): void
    {
        $client = self::signedInWith([BillingPermissions::VIEW, BillingPermissions::EDIT]);
        self::buildTheLetProperty();
        self::theLandlordForgetsTheirTaxNumber();

        $invoice = self::started($client);
        $crawler = $client->request('GET', self::stepUrl($invoice, 'ausstellung'));

        self::assertCount(1, $crawler->filter('.ib-note--warning'));
        self::assertStringContainsString('Steuernummer', $crawler->filter('.ib-note--warning')->text());
        self::assertCount(0, $crawler->filter('form[action$="/ausstellen"]'), 'Und kein Knopf');
    }

    /** Auch wer das Formular umgeht, stellt nichts aus. */
    public function testIssuingWithAGapIsRefused(): void
    {
        $client = self::signedInWith([BillingPermissions::VIEW, BillingPermissions::EDIT]);
        self::buildTheLetProperty();
        self::theLandlordForgetsTheirTaxNumber();
        $invoice = self::started($client);

        $crawler = $client->request('GET', self::stepUrl($invoice, 'ausstellung'));
        $client->request('POST', '/billing/dauermietrechnungen/'.$invoice->id().'/ausstellen', [
            '_token' => self::tokenOn($crawler),
        ]);

        self::assertTrue(self::reloaded($invoice)->release()->isDraft(), 'Nichts eingefroren');
    }

    /** Eine ausgestellte Rechnung wird nicht mehr bearbeitet. */
    public function testAnIssuedInvoiceCannotBeEdited(): void
    {
        $client = self::signedInWith([BillingPermissions::VIEW, BillingPermissions::EDIT]);
        self::buildTheLetProperty();
        $invoice = self::issued($client);

        $client->request('GET', self::stepUrl($invoice, 'rechnung'));

        self::assertResponseRedirects('/billing/dauermietrechnungen/'.$invoice->id());
    }

    /** Die Folgefassung entsteht mit einem Klick — vorbelegt mit der naechsten Stufe. */
    public function testASuccessorStartsWithOneClick(): void
    {
        $client = self::signedInWith([BillingPermissions::VIEW, BillingPermissions::EDIT]);
        self::buildTheLetProperty();
        $invoice = self::issued($client);
        self::alsoRaisesTheRent('2027-07-01');

        $crawler = $client->request('GET', '/billing/dauermietrechnungen/'.$invoice->id());
        self::assertResponseIsSuccessful();
        $client->request('POST', '/billing/dauermietrechnungen/'.$invoice->id().'/neu-ausstellen', [
            '_token' => self::tokenOn($crawler),
        ]);

        $next = self::latestDraft();
        self::assertSame('2027-07-01', $next->validity()->from()->format('Y-m-d'));
        self::assertSame(2, $next->edition()->number(), 'Die zweite Fassung');
        self::assertResponseRedirects('/billing/dauermietrechnungen/'.$next->id().'/bearbeiten');
    }

    /**
     * Zweimal „Neu ausstellen" fuehrt auf denselben Entwurf.
     *
     * Und sagt auch, warum: wer klickt und auf einem Entwurf von vorletzter
     * Woche landet, haelt das sonst fuer einen Fehler.
     */
    public function testASecondSuccessorLandsOnTheDraftThatExists(): void
    {
        $client = self::signedInWith([BillingPermissions::VIEW, BillingPermissions::EDIT]);
        self::buildTheLetProperty();
        $invoice = self::issued($client);
        self::alsoRaisesTheRent('2027-07-01');

        $crawler = $client->request('GET', '/billing/dauermietrechnungen/'.$invoice->id());
        $client->request('POST', '/billing/dauermietrechnungen/'.$invoice->id().'/neu-ausstellen', [
            '_token' => self::tokenOn($crawler),
        ]);
        $first = self::latestDraft();

        $client->request('POST', '/billing/dauermietrechnungen/'.$invoice->id().'/neu-ausstellen', [
            '_token' => self::tokenOn($crawler),
        ]);

        self::assertResponseRedirects('/billing/dauermietrechnungen/'.$first->id().'/bearbeiten');
        self::assertCount(2, self::all(), 'Kein zweiter Entwurf daneben');
        self::assertStringContainsString(
            'Es gibt schon einen Entwurf',
            $client->followRedirect()->filter('.ib-flash')->text(),
        );
    }

    /**
     * Aus der Reihe laesst sich nichts ausstellen, und es steht auch da.
     *
     * Zwei offene Fassungen ueber denselben Zeitraum waeren zwei Rechnungen
     * beim Mieter — der Fehler gehoert auf den Bildschirm und nicht in ein
     * Protokoll.
     */
    public function testIssuingOutOfOrderSaysSo(): void
    {
        $client = self::signedInWith([BillingPermissions::VIEW, BillingPermissions::EDIT]);
        self::buildTheLetProperty();
        $invoice = self::issued($client);
        $next = self::revise()->succeed($invoice, new DateTimeImmutable('2026-01-01'));

        $crawler = $client->request('GET', self::stepUrl($next, 'ausstellung'));
        $client->request('POST', '/billing/dauermietrechnungen/'.$next->id().'/ausstellen', [
            '_token' => self::tokenOn($crawler),
        ]);

        self::assertTrue(self::reloaded($next)->release()->isDraft(), 'Nichts ausgestellt');

        // Zweimal: die Ansicht schickt einen Entwurf in den Ablauf zurueck.
        $client->followRedirect();

        self::assertStringContainsString(
            'beginnt nicht nach der zuletzt ausgestellten',
            $client->followRedirect()->filter('.ib-flash')->text(),
        );
    }

    /**
     * Ein liegengebliebener Berichtigungsentwurf laesst sich nicht ausstellen.
     *
     * Er traegt den Zeitraum der Fassung, die er berichtigt — offen, wie
     * sie damals war. Ginge er nach der Folgefassung hinaus, laege er ueber
     * ihr. Der Fehler sagt, was zu tun ist: loeschen und die neue
     * berichtigen.
     */
    public function testAStaleCorrectionSaysWhatToDo(): void
    {
        $client = self::signedInWith([BillingPermissions::VIEW, BillingPermissions::EDIT]);
        self::buildTheLetProperty();
        // Frisch geladen: der Client startet den Kernel zwischen den
        // Anfragen neu, und das Objekt von vorhin gehoert einem anderen
        // Entity-Manager.
        $invoice = self::reloaded(self::issued($client));
        $correction = self::revise()->correct($invoice);

        self::alsoRaisesTheRent('2027-07-01');
        $next = self::revise()->succeed($invoice, new DateTimeImmutable('2027-07-01'));
        $client->request('GET', self::stepUrl($next, 'ausstellung'));
        $client->submitForm('Ausstellen');

        $crawler = $client->request('GET', self::stepUrl($correction, 'ausstellung'));
        $client->request('POST', '/billing/dauermietrechnungen/'.$correction->id().'/ausstellen', [
            '_token' => self::tokenOn($crawler),
        ]);

        self::assertTrue(self::reloaded($correction)->release()->isDraft(), 'Nichts ausgestellt');
        $client->followRedirect();

        self::assertStringContainsString(
            'ist inzwischen abgelöst',
            $client->followRedirect()->filter('.ib-flash')->text(),
        );
    }

    /**
     * Solange die Berichtigung Entwurf ist, gilt das Original weiter.
     *
     * „Berichtigt" heisst: es gibt ein Schreiben, das an die Stelle dieses
     * getreten ist. Ein Entwurf ist kein Schreiben — er liegt hier und
     * nicht beim Mieter. Stuende die Marke schon beim Anlegen da, waere
     * die einzige gueltige Rechnung des Vertrags als abgeloest gekennzeichnet.
     */
    public function testADraftCorrectionDoesNotMarkTheOriginalYet(): void
    {
        $client = self::signedInWith([BillingPermissions::VIEW, BillingPermissions::EDIT]);
        self::buildTheLetProperty();
        $invoice = self::issued($client);

        $crawler = $client->request('GET', '/billing/dauermietrechnungen/'.$invoice->id());
        $client->request('POST', '/billing/dauermietrechnungen/'.$invoice->id().'/berichtigen', [
            '_token' => self::tokenOn($crawler),
        ]);

        $rows = $client->request('GET', '/billing/dauermietrechnungen')->filter('tbody tr');

        self::assertSame(2, $rows->count());
        self::assertStringNotContainsString('Berichtigt', $rows->eq(1)->text(), 'Das Original gilt weiter');
        self::assertStringContainsString('Ausgestellt', $rows->eq(1)->text());
    }

    /** Und zweimal „Berichtigen" fuehrt auf denselben Entwurf. */
    public function testASecondCorrectionLandsOnTheDraftThatExists(): void
    {
        $client = self::signedInWith([BillingPermissions::VIEW, BillingPermissions::EDIT]);
        self::buildTheLetProperty();
        $invoice = self::issued($client);

        $crawler = $client->request('GET', '/billing/dauermietrechnungen/'.$invoice->id());
        $client->request('POST', '/billing/dauermietrechnungen/'.$invoice->id().'/berichtigen', [
            '_token' => self::tokenOn($crawler),
        ]);
        $first = self::latestDraft();

        $client->request('POST', '/billing/dauermietrechnungen/'.$invoice->id().'/berichtigen', [
            '_token' => self::tokenOn($crawler),
        ]);

        self::assertResponseRedirects('/billing/dauermietrechnungen/'.$first->id().'/bearbeiten');
        self::assertCount(2, self::all(), 'Kein zweiter Entwurf daneben');
        self::assertStringContainsString(
            'Es gibt schon einen Berichtigungsentwurf',
            $client->followRedirect()->filter('.ib-flash')->text(),
        );
    }

    /** Und die Berichtigung genauso — mit demselben Zeitraum. */
    public function testACorrectionStartsWithOneClick(): void
    {
        $client = self::signedInWith([BillingPermissions::VIEW, BillingPermissions::EDIT]);
        self::buildTheLetProperty();
        $invoice = self::issued($client);

        $crawler = $client->request('GET', '/billing/dauermietrechnungen/'.$invoice->id());
        $client->request('POST', '/billing/dauermietrechnungen/'.$invoice->id().'/berichtigen', [
            '_token' => self::tokenOn($crawler),
        ]);

        $correction = self::latestDraft();
        self::assertSame('2026-01-01', $correction->validity()->from()->format('Y-m-d'));
        self::assertSame(2, $correction->edition()->iteration());
    }

    /**
     * Ein Entwurf laesst sich nicht berichtigen — auch nicht per POST.
     *
     * Sonst entstuende neben dem Entwurf der Iteration 1 ein zweiter der
     * Iteration 2: zwei Schreiben ueber denselben Zeitraum, beide
     * ausstellbar, und der Mieter muesste raten, welches gilt.
     */
    public function testADraftCannotBeCorrectedByPost(): void
    {
        $client = self::signedInWith([BillingPermissions::VIEW, BillingPermissions::EDIT]);
        self::buildTheLetProperty();
        $invoice = self::started($client);

        $crawler = $client->request('GET', self::stepUrl($invoice, 'ausstellung'));
        $client->request('POST', '/billing/dauermietrechnungen/'.$invoice->id().'/berichtigen', [
            '_token' => self::tokenOn($crawler),
        ]);

        self::assertCount(1, self::all(), 'Keine zweite Fassung');
        self::assertResponseRedirects('/billing/dauermietrechnungen/'.$invoice->id());
    }

    /** Ein Entwurf hat nie gegolten — er wird geloescht. */
    public function testADraftCanBeDiscarded(): void
    {
        $client = self::signedInWith([BillingPermissions::VIEW, BillingPermissions::EDIT]);
        self::buildTheLetProperty();
        $invoice = self::started($client);

        $crawler = $client->request('GET', self::stepUrl($invoice, 'rechnung'));
        $client->request('POST', '/billing/dauermietrechnungen/'.$invoice->id().'/loeschen', [
            '_token' => self::tokenOn($crawler),
        ]);

        self::assertResponseRedirects('/billing/dauermietrechnungen');
        self::assertCount(0, self::all());
    }

    /** Eine ausgestellte dagegen nicht: sie liegt beim Mieter. */
    public function testAnIssuedInvoiceCannotBeDiscarded(): void
    {
        $client = self::signedInWith([BillingPermissions::VIEW, BillingPermissions::EDIT]);
        self::buildTheLetProperty();
        $invoice = self::issued($client);

        $crawler = $client->request('GET', '/billing/dauermietrechnungen/'.$invoice->id());
        $client->request('POST', '/billing/dauermietrechnungen/'.$invoice->id().'/loeschen', [
            '_token' => self::tokenOn($crawler),
        ]);

        self::assertCount(1, self::all());
    }

    /** Ohne Bearbeitungsrecht gibt es den Ablauf nicht. */
    public function testTheFlowNeedsTheEditPermission(): void
    {
        $client = self::signedInWith([BillingPermissions::VIEW]);
        self::buildTheLetProperty();

        $client->request('GET', '/billing/dauermietrechnungen/neu');

        self::assertResponseStatusCodeSame(403);
    }

    /**
     * Wer nur lesen darf, sieht die Rechnung — und keinen Knopf.
     *
     * Die Rechnung entsteht hier ohne Klicken: der Leser darf den Ablauf
     * nicht, und ohne ihn gaebe es nichts, was er ansehen koennte.
     */
    public function testAReaderSeesTheInvoiceWithoutButtons(): void
    {
        $reader = self::signedInWith([BillingPermissions::VIEW]);
        self::buildTheLetProperty();
        $invoice = self::anIssuedInvoice();

        $crawler = $reader->request('GET', '/billing/dauermietrechnungen/'.$invoice->id());

        self::assertResponseIsSuccessful();
        self::assertStringContainsString($invoice->reference(), $crawler->filter('.ib-preview')->text());
        self::assertCount(
            0,
            $crawler->filter('.ib-index__actions [data-modal-open]'),
            'Kein Berichtigen, kein Neu ausstellen',
        );
        self::assertCount(1, $crawler->filter('a[href$="/pdf"]'), 'Das PDF darf er laden');
    }

    /** Zur Wahl stehen nur Mietverhaeltnisse, die in Kraft sind. */
    public function testOnlyLettableTenanciesAreOffered(): void
    {
        $client = self::signedInWith([BillingPermissions::VIEW, BillingPermissions::EDIT]);
        self::buildTheLetProperty();

        $crawler = $client->request('GET', '/billing/dauermietrechnungen/neu');

        self::assertSame(1, $crawler->filter('#tenancyId option[value="'.self::letTenancyId().'"]')->count());
    }

    /** Ohne Mietverhaeltnis entsteht keine Rechnung, und der Grund steht da. */
    public function testTheFirstStepNeedsATenancy(): void
    {
        $client = self::signedInWith([BillingPermissions::VIEW, BillingPermissions::EDIT]);
        self::buildTheLetProperty();

        $crawler = $client->request('GET', '/billing/dauermietrechnungen/neu');
        $client->request('POST', '/billing/dauermietrechnungen/neu', [
            '_token' => self::tokenOn($crawler),
            'tenancyId' => '',
            'appliesFrom' => '2026-01-01',
            'label' => 'Ohne Vertrag',
        ]);

        self::assertResponseIsSuccessful();
        self::assertCount(0, self::all());
        self::assertStringContainsString('Mietverhältnis', $client->getCrawler()->filter('.ib-field__error')->text());
    }

    /**
     * In der Liste steht die Berichtigung ueber dem Schreiben, das sie
     * berichtigt — und sie sagt, dass sie eine ist.
     *
     * Beide tragen denselben Zeitraum. Stuenden sie ohne Marke und in
     * beliebiger Reihenfolge da, muesste jeder Leser die Rechnungsnummer
     * entziffern, um zu wissen, welche gilt.
     */
    public function testACorrectionIsMarkedAndStandsAboveTheOriginal(): void
    {
        $client = self::signedInWith([BillingPermissions::VIEW, BillingPermissions::EDIT]);
        self::buildTheLetProperty();
        $invoice = self::issued($client);

        $crawler = $client->request('GET', '/billing/dauermietrechnungen/'.$invoice->id());
        $client->request('POST', '/billing/dauermietrechnungen/'.$invoice->id().'/berichtigen', [
            '_token' => self::tokenOn($crawler),
        ]);
        $correction = self::latestDraft();
        $client->request('GET', self::stepUrl($correction, 'ausstellung'));
        $client->submitForm('Ausstellen');

        $rows = $client->request('GET', '/billing/dauermietrechnungen')->filter('tbody tr');

        self::assertSame(2, $rows->count());
        self::assertStringContainsString('Berichtigung', $rows->eq(0)->text());
        self::assertStringContainsString($correction->reference(), $rows->eq(0)->text());
        self::assertStringNotContainsString('Berichtigung', $rows->eq(1)->text());

        // Und die berichtigte Fassung sagt, dass sie es ist: beide tragen
        // denselben Zeitraum, und ohne den Zustand sähe die alte aus, als
        // gälte sie weiter.
        self::assertStringContainsString('Berichtigt', $rows->eq(1)->text());
        self::assertStringNotContainsString('Berichtigt', $rows->eq(0)->text());
    }

    /** Die Liste filtert nach Objekt. */
    public function testTheListFiltersByProperty(): void
    {
        $client = self::signedInWith([BillingPermissions::VIEW, BillingPermissions::EDIT]);
        self::buildTheLetProperty();
        self::issued($client);

        $found = $client->request('GET', '/billing/dauermietrechnungen?objekt='.self::letPropertyId());
        self::assertSame(1, $found->filter('tbody tr')->count());

        $empty = $client->request('GET', '/billing/dauermietrechnungen?q=Gibtesnicht');
        self::assertSame(0, $empty->filter('tbody tr')->count());
    }

    protected static function testEmail(): string
    {
        return 'mietrechnungklick@example.org';
    }

    private static function started(KernelBrowser $client): RentInvoice
    {
        $client->request('GET', '/billing/dauermietrechnungen/neu');
        $client->submitForm('Weiter', [
            'tenancyId' => self::letTenancyId(),
            'appliesFrom' => '2026-01-01',
            'label' => 'Dauermietrechnung ab 2026',
        ]);

        $all = self::all();
        self::assertCount(1, $all);

        return $all[0];
    }

    private static function issued(KernelBrowser $client): RentInvoice
    {
        $invoice = self::started($client);
        $client->request('GET', self::stepUrl($invoice, 'ausstellung'));
        $client->submitForm('Ausstellen');
        self::assertFalse(self::reloaded($invoice)->release()->isDraft());

        return $invoice;
    }

    /** Eine ausgestellte Rechnung, ohne Klicken angelegt. */
    private static function anIssuedInvoice(): RentInvoice
    {
        $start = self::getContainer()->get(StartRentInvoice::class);
        self::assertInstanceOf(StartRentInvoice::class, $start);
        $invoice = $start->forTenancy(self::letTenancyId(), new DateTimeImmutable('2026-01-01'), 'Zum Lesen');
        self::assertInstanceOf(RentInvoice::class, $invoice);

        $issue = self::getContainer()->get(IssueRentInvoice::class);
        self::assertInstanceOf(IssueRentInvoice::class, $issue);
        $issue->issue($invoice, new DateTimeImmutable('2026-01-02'));

        return $invoice;
    }

    private static function revise(): ReviseRentInvoice
    {
        $revise = self::getContainer()->get(ReviseRentInvoice::class);
        self::assertInstanceOf(ReviseRentInvoice::class, $revise);

        return $revise;
    }

    private static function latestDraft(): RentInvoice
    {
        foreach (self::all() as $invoice) {
            if ($invoice->release()->isDraft()) {
                return $invoice;
            }
        }

        self::fail('Kein Entwurf gefunden.');
    }

    /** @return list<RentInvoice> */
    private static function all(): array
    {
        return self::invoices()->matching(RentInvoiceFilter::none(), Page::of(1, 10));
    }

    private static function reloaded(RentInvoice $invoice): RentInvoice
    {
        $found = self::invoices()->byId($invoice->id());
        self::assertInstanceOf(RentInvoice::class, $found);

        return $found;
    }

    private static function stepUrl(RentInvoice $invoice, string $step): string
    {
        return '/billing/dauermietrechnungen/'.$invoice->id().'/bearbeiten/'.$step;
    }

    private static function theLandlordForgetsTheirTaxNumber(): void
    {
        $parties = self::getContainer()->get(PartyRepository::class);
        self::assertInstanceOf(PartyRepository::class, $parties);

        foreach ($parties->matching(PartyFilter::none(), Page::of(1, 500)) as $party) {
            if (98001 === $party->reference()) {
                $party->taxedAs(TaxId::of(''));
                $parties->save($party);
            }
        }
    }

    private static function invoices(): RentInvoiceRepository
    {
        $invoices = self::getContainer()->get(RentInvoiceRepository::class);
        self::assertInstanceOf(RentInvoiceRepository::class, $invoices);

        return $invoices;
    }

    private static function tokenOn(Crawler $crawler): string
    {
        $token = $crawler->filter('main input[name="_token"]')->first();

        return 0 === $token->count() ? '' : (string) $token->attr('value');
    }
}
