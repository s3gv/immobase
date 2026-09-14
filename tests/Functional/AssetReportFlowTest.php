<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Module\Billing\Application\ReleaseAssetReport;
use App\Module\Billing\Application\StartAssetReport;
use App\Module\Billing\Domain\AssetItem;
use App\Module\Billing\Domain\AssetReport;
use App\Module\Billing\Domain\AssetReportFilter;
use App\Module\Billing\Domain\AssetReportRepository;
use App\Module\Billing\Domain\BillingPermissions;
use App\Module\Finance\Domain\ReserveMovementKind;
use App\Shared\Money\Money;
use App\Shared\Ui\Page;
use DateTimeImmutable;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;

/**
 * Der Ablauf, so wie ihn jemand durchklickt.
 *
 * Vier Schritte und kein Beschluss: § 28 Abs. 4 WEG verlangt eine Auskunft.
 * Der Weg fuehrt trotzdem ueber eine Herausgabe — was zugestellt ist, aendert
 * sich nicht mehr.
 */
final class AssetReportFlowTest extends WebTestCase
{
    use BuildsABillableProperty;
    use ForgetsRateLimits;
    use SignsIn;

    private const int REPORT_YEAR = 2025;

    protected function tearDown(): void
    {
        self::removeTheProperty();
        self::removeTestUser();

        parent::tearDown();
    }

    /** Vom leeren Formular bis zur Post. */
    public function testTheWholeFlowFromTheFirstStepToTheIssue(): void
    {
        $client = self::signedInWith([BillingPermissions::VIEW, BillingPermissions::EDIT]);
        self::buildTheProperty();
        self::alsoMovedTheReserve(ReserveMovementKind::Opening, Money::fromCents(400000), '2024-01-01');
        self::alsoMovedTheReserve(ReserveMovementKind::Contribution, Money::fromCents(120000), '2025-06-30');

        $report = self::started($client);

        self::assertSame('Vermögensbericht 2025', $report->label());
        self::assertCount(1, $report->items(), 'Eine leere Kontozeile steht schon da');

        // Der Rücklagenschritt hat keine Felder — er zeigt, was die Anwendung
        // selbst weiß.
        $reserve = $client->request('GET', self::stepUrl($report, 'ruecklage'));
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('5.200,00', $reserve->filter('.ib-stats')->text());

        self::fillTheAssets($client, $report);

        $issue = $client->request('GET', self::stepUrl($report, 'herausgabe'));
        self::assertCount(0, $issue->filter('.ib-note--warning'), 'Nichts fehlt mehr');
        self::assertStringContainsString('Girokonto', $issue->filter('.ib-preview')->text());

        $client->submitForm('Herausgeben');
        self::assertResponseRedirects('/billing/vermoegensberichte/'.$report->id());

        $released = self::reloaded($report);
        self::assertFalse($released->release()->isDraft());
        self::assertCount(2, $released->documents(), 'Je Einheit ein Schreiben');
        self::assertSame(520000, $released->reserve()->closing()->cents(), 'Der Stand ist festgeschrieben');

        // Und die Liste zeigt ihn auch: ein Zustand, den keine Seite zeichnen
        // kann, ist keiner.
        $list = $client->request('GET', '/billing/vermoegensberichte');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Herausgegeben', $list->filter('tbody')->text());
    }

    /**
     * Ohne Betrag geht nichts heraus.
     *
     * Ein Konto ohne Stand ist keine Auskunft, sondern eine Luecke — und der
     * Knopf ist gar nicht erst da.
     */
    public function testAMissingAmountHidesTheIssueButton(): void
    {
        $client = self::signedInWith([BillingPermissions::VIEW, BillingPermissions::EDIT]);
        self::buildTheProperty();
        $report = self::started($client);

        $crawler = $client->request('GET', self::stepUrl($report, 'herausgabe'));

        self::assertCount(1, $crawler->filter('.ib-note--warning'), 'Der Hinweis steht da');
        self::assertCount(0, $crawler->filter('form[action$="/herausgeben"]'), 'Und kein Knopf');
    }

    /**
     * Auch wer das Formular umgeht, gibt nichts heraus.
     *
     * Ein Knopf, den man umgehen kann, ist keine Sicherung.
     */
    public function testIssuingWithAMissingAmountIsRefused(): void
    {
        $client = self::signedInWith([BillingPermissions::VIEW, BillingPermissions::EDIT]);
        self::buildTheProperty();
        $report = self::started($client);

        $crawler = $client->request('GET', self::stepUrl($report, 'herausgabe'));
        $client->request('POST', '/billing/vermoegensberichte/'.$report->id().'/herausgeben', [
            '_token' => self::tokenOn($crawler),
        ]);

        self::assertTrue(self::reloaded($report)->release()->isDraft(), 'Nichts festgeschrieben');
    }

    /** Eine Zeile hinzufuegen bleibt auf dem Schritt stehen. */
    public function testAddingARowStaysOnTheStep(): void
    {
        $client = self::signedInWith([BillingPermissions::VIEW, BillingPermissions::EDIT]);
        self::buildTheProperty();
        $report = self::started($client);

        $crawler = $client->request('GET', self::stepUrl($report, 'vermoegen'));
        $client->request('POST', self::stepUrl($report, 'vermoegen'), [
            '_token' => self::tokenOn($crawler),
            'add' => 'liability',
        ]);

        self::assertResponseRedirects(self::stepUrl($report, 'vermoegen'));
        self::assertCount(2, self::reloaded($report)->items());
    }

    /** Und eine entfernen auch. */
    public function testARowCanBeRemoved(): void
    {
        $client = self::signedInWith([BillingPermissions::VIEW, BillingPermissions::EDIT]);
        self::buildTheProperty();
        $report = self::started($client);
        $item = $report->items()[0] ?? null;
        self::assertInstanceOf(AssetItem::class, $item);

        $crawler = $client->request('GET', self::stepUrl($report, 'vermoegen'));
        $client->request('POST', self::stepUrl($report, 'vermoegen'), [
            '_token' => self::tokenOn($crawler),
            'remove' => $item->id(),
        ]);

        self::assertCount(0, self::reloaded($report)->items());
    }

    /** Ein herausgegebener Bericht laesst sich nicht mehr bearbeiten. */
    public function testAnIssuedReportCannotBeEdited(): void
    {
        $client = self::signedInWith([BillingPermissions::VIEW, BillingPermissions::EDIT]);
        self::buildTheProperty();
        $report = self::started($client);
        self::fillTheAssets($client, $report);
        $client->request('GET', self::stepUrl($report, 'herausgabe'));
        $client->submitForm('Herausgeben');

        $client->request('GET', self::stepUrl($report, 'vermoegen'));

        self::assertResponseStatusCodeSame(403);
    }

    /** Berichtigt wird ueber eine neue Fassung — mit einem Klick. */
    public function testACorrectedVersionStartsWithOneClick(): void
    {
        $client = self::signedInWith([BillingPermissions::VIEW, BillingPermissions::EDIT]);
        self::buildTheProperty();
        $report = self::started($client);
        self::fillTheAssets($client, $report);
        $client->request('GET', self::stepUrl($report, 'herausgabe'));
        $client->submitForm('Herausgeben');

        $client->request('GET', '/billing/vermoegensberichte/'.$report->id());
        self::assertResponseIsSuccessful();
        $client->submitForm('Berichtigen');

        $all = self::reports()->matching(AssetReportFilter::none(), Page::of(1, 10));
        self::assertCount(2, $all, 'Die Fassung steht neben dem Original');
        self::assertResponseRedirects();
    }

    /**
     * Ein Entwurf wird bearbeitet und nicht berichtigt.
     *
     * Sonst entstuende neben dem Entwurf der Iteration 1 ein zweiter der
     * Iteration 2, beide bearbeitbar und beide herausgebbar — zwei Auskuenfte
     * ueber denselben Stichtag, und der Empfaenger muesste raten, welche gilt.
     *
     * Der Knopf dafuer steht nirgends; die Adresse laesst sich trotzdem
     * aufrufen, und ein Formular ist Eingabe und keine Zusicherung.
     */
    public function testADraftCannotBeCorrected(): void
    {
        $client = self::signedInWith([BillingPermissions::VIEW, BillingPermissions::EDIT]);
        self::buildTheProperty();
        $report = self::started($client);

        $crawler = $client->request('GET', self::stepUrl($report, 'vermoegen'));
        $client->request('POST', '/billing/vermoegensberichte/'.$report->id().'/berichtigen', [
            '_token' => self::tokenOn($crawler),
        ]);

        self::assertResponseRedirects('/billing/vermoegensberichte');
        self::assertCount(1, self::reports()->matching(AssetReportFilter::none(), Page::of(1, 10)), 'Keine zweite Fassung');
    }

    /**
     * Berichtigt wird die juengste Fassung und keine ueberholte.
     *
     * Die zweite Fassung ersetzt die erste. Wer die erste noch einmal
     * berichtigte, schriebe an einer Auskunft weiter, die es so nicht mehr
     * gibt — und liefe mit der Iteration in die schon vergebene Nummer.
     */
    public function testAnOutdatedVersionCannotBeCorrected(): void
    {
        $client = self::signedInWith([BillingPermissions::VIEW, BillingPermissions::EDIT]);
        self::buildTheProperty();
        $first = self::started($client);
        self::issue($client, $first);

        $client->request('GET', '/billing/vermoegensberichte/'.$first->id());
        $client->submitForm('Berichtigen');
        $second = self::latestDraft();
        self::issue($client, $second);

        $shown = $client->request('GET', '/billing/vermoegensberichte/'.$first->id());
        self::assertStringNotContainsString('Berichtigen', $shown->filter('.ib-flow__actions')->text(), 'Kein Knopf');

        // Das Formular gibt es auf dieser Seite nicht mehr — die Adresse
        // trotzdem, und genau darum geht es.
        $client->request('POST', '/billing/vermoegensberichte/'.$first->id().'/berichtigen', [
            '_token' => self::aFormToken($client),
        ]);
        $client->followRedirect();

        self::assertCount(2, self::reports()->matching(AssetReportFilter::none(), Page::of(1, 10)), 'Keine dritte Fassung');
        // Und die Absage sagt, warum — nicht, dass jemand anderes schneller war.
        self::assertSelectorTextContains('.ib-flash', 'jüngste Fassung');
    }

    /**
     * Ein Entwurf ist keine Post — er wird auch nicht angesehen.
     *
     * Die Liste verlinkt ihn nicht, die Adresse ist trotzdem aufrufbar. Auf
     * der Ansichtsseite stuenden Kontostaende, der Ruecklagenstand und die
     * offenen Hausgelder, frisch gerechnet: Zahlen, die noch niemand
     * herausgegeben hat.
     */
    public function testADraftCannotBeViewed(): void
    {
        $client = self::signedInWith([BillingPermissions::VIEW, BillingPermissions::EDIT]);
        self::buildTheProperty();
        $report = self::started($client);

        $client->request('GET', '/billing/vermoegensberichte/'.$report->id());

        self::assertResponseStatusCodeSame(404);
    }

    /**
     * Wer nur lesen darf, sieht den herausgegebenen Bericht — und den Entwurf nicht.
     *
     * Das Leserecht ist das weiteste im Modul; was es sieht, sieht jeder im
     * Haus.
     */
    public function testAReaderSeesTheIssuedReportAndNotTheDraft(): void
    {
        $reader = self::signedInWith([BillingPermissions::VIEW]);
        self::buildTheProperty();
        $issued = self::anIssuedReport();
        $draft = self::aSecondDraft();

        $reader->request('GET', '/billing/vermoegensberichte/'.$issued->id());
        self::assertResponseIsSuccessful();

        $reader->request('GET', '/billing/vermoegensberichte/'.$draft->id());
        self::assertResponseStatusCodeSame(404);
    }

    /** Ein Entwurf laesst sich loeschen. */
    public function testADraftCanBeDeleted(): void
    {
        $client = self::signedInWith([BillingPermissions::VIEW, BillingPermissions::EDIT]);
        self::buildTheProperty();
        $report = self::started($client);

        $crawler = $client->request('GET', self::stepUrl($report, 'vermoegen'));
        $client->request('POST', '/billing/vermoegensberichte/'.$report->id().'/loeschen', [
            '_token' => self::tokenOn($crawler),
        ]);

        self::assertResponseRedirects('/billing/vermoegensberichte');
        self::assertSame(0, self::reports()->countMatching(AssetReportFilter::none()));
    }

    /** Ohne Bearbeitungsrecht gibt es den Ablauf nicht. */
    public function testTheFlowNeedsTheEditPermission(): void
    {
        $client = self::signedInWith([BillingPermissions::VIEW]);
        self::buildTheProperty();

        $client->request('GET', '/billing/vermoegensberichte/neu');

        self::assertResponseStatusCodeSame(403);
    }

    /** Ein laufendes Jahr steht nicht zur Wahl. */
    public function testOnlyFinishedYearsAreOffered(): void
    {
        $client = self::signedInWith([BillingPermissions::VIEW, BillingPermissions::EDIT]);
        self::buildTheProperty();

        $crawler = $client->request('GET', '/billing/vermoegensberichte/neu');
        $running = (int) date('Y');

        self::assertSame(0, $crawler->filter('#fiscalYear option[value="'.$running.'"]')->count());
        self::assertSame(1, $crawler->filter('#fiscalYear option[value="'.($running - 1).'"]')->count());
    }

    protected static function testEmail(): string
    {
        return 'berichtklick@example.org';
    }

    private static function started(KernelBrowser $client): AssetReport
    {
        $client->request('GET', '/billing/vermoegensberichte/neu');
        $client->submitForm('Weiter', [
            'property' => (string) self::PROPERTY_NUMBER,
            'fiscalYear' => (string) self::REPORT_YEAR,
            'label' => 'Vermögensbericht '.self::REPORT_YEAR,
        ]);

        $reports = self::reports()->matching(AssetReportFilter::none(), Page::of(1, 10));
        self::assertCount(1, $reports);

        return $reports[0];
    }

    /** Ausfuellen und herausgeben — der ganze Weg in einem Griff. */
    private static function issue(KernelBrowser $client, AssetReport $report): void
    {
        self::fillTheAssets($client, $report);
        $client->request('GET', self::stepUrl($report, 'herausgabe'));
        $client->submitForm('Herausgeben');
        self::assertFalse(self::reloaded($report)->release()->isDraft());
    }

    /** Die offene Fassung — nach einer Berichtigung ist das die neue. */
    private static function latestDraft(): AssetReport
    {
        foreach (self::reports()->matching(AssetReportFilter::none(), Page::of(1, 10)) as $report) {
            if ($report->release()->isDraft()) {
                return $report;
            }
        }

        self::fail('Kein Entwurf gefunden.');
    }

    /**
     * Ein herausgegebener Bericht, ohne Klicken angelegt.
     *
     * Fuer den Leser: er darf den Ablauf nicht, und ohne ihn kaeme kein
     * Bericht zustande, den er ansehen koennte.
     */
    private static function anIssuedReport(): AssetReport
    {
        $report = self::aDraftFor(self::REPORT_YEAR);
        $item = $report->items()[0] ?? null;
        self::assertInstanceOf(AssetItem::class, $item);
        $item->describe('Girokonto', Money::fromCents(520000), false, '');
        self::reports()->save($report);

        $release = self::getContainer()->get(ReleaseAssetReport::class);
        self::assertInstanceOf(ReleaseAssetReport::class, $release);
        $release->release($report, new DateTimeImmutable('2026-02-15'));

        return $report;
    }

    /** Ein zweiter Entwurf, ueber ein anderes Jahr — zum Ansehen, was niemand sehen soll. */
    private static function aSecondDraft(): AssetReport
    {
        return self::aDraftFor(self::REPORT_YEAR - 1);
    }

    private static function aDraftFor(int $year): AssetReport
    {
        $start = self::getContainer()->get(StartAssetReport::class);
        self::assertInstanceOf(StartAssetReport::class, $start);

        $report = $start->forProperty(self::PROPERTY_NUMBER, $year, 'Vermögensbericht '.$year);
        self::assertInstanceOf(AssetReport::class, $report);

        return $report;
    }

    /** Die Kontozeile ausfuellen und eine Verbindlichkeit dazu. */
    private static function fillTheAssets(KernelBrowser $client, AssetReport $report): void
    {
        $item = self::reloaded($report)->items()[0] ?? null;
        self::assertInstanceOf(AssetItem::class, $item);

        $client->request('GET', self::stepUrl($report, 'vermoegen'));
        $client->submitForm('Weiter', [
            'rows['.$item->id().'][label]' => 'Girokonto',
            'rows['.$item->id().'][amount]' => '5.200,00',
            'rows['.$item->id().'][earmarked]' => '1',
        ]);
        self::assertResponseRedirects(self::stepUrl($report, 'herausgabe'));
    }

    private static function stepUrl(AssetReport $report, string $step): string
    {
        return '/billing/vermoegensberichte/'.$report->id().'/bearbeiten/'.$step;
    }

    private static function reloaded(AssetReport $report): AssetReport
    {
        $found = self::reports()->byId($report->id());
        self::assertInstanceOf(AssetReport::class, $found);

        return $found;
    }

    /**
     * Ein gueltiges Formular-Token, von irgendeiner Seite des Ablaufs.
     *
     * Fuer die Faelle, in denen das Formular gerade fehlt: der Angriff hat es
     * auch nicht, aber ein Token besorgt er sich anderswo.
     */
    private static function aFormToken(KernelBrowser $client): string
    {
        return self::tokenOn($client->request('GET', '/billing/vermoegensberichte/neu'));
    }

    private static function tokenOn(Crawler $crawler): string
    {
        $token = $crawler->filter('main input[name="_token"]')->first();

        return 0 === $token->count() ? '' : (string) $token->attr('value');
    }

    private static function reports(): AssetReportRepository
    {
        $reports = self::getContainer()->get(AssetReportRepository::class);
        self::assertInstanceOf(AssetReportRepository::class, $reports);

        return $reports;
    }
}
