<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Module\Billing\Application\PlanPositions;
use App\Module\Billing\Domain\BillingPermissions;
use App\Module\Billing\Domain\Plan;
use App\Module\Billing\Domain\PlanFilter;
use App\Module\Billing\Domain\PlanPosition;
use App\Module\Billing\Domain\PlanRepository;
use App\Module\Billing\Domain\ResolutionStatus;
use App\Module\Finance\Application\MaintainDistributionKeys;
use App\Module\Finance\Contract\PlanSources;
use App\Module\Finance\Domain\DistributionKey;
use App\Module\Finance\Domain\DistributionKeyKind;
use App\Module\Finance\Domain\DistributionKeyRepository;
use App\Module\Finance\Domain\UsedByAPlan;
use App\Module\Property\Domain\Measures;
use App\Module\Property\Domain\Property;
use App\Module\Property\Domain\PropertyRepository;
use App\Module\Property\Domain\Unit;
use App\Shared\Money\Money;
use App\Shared\Ui\Page;
use DOMElement;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;

/**
 * Der Ablauf, so wie ihn jemand durchklickt.
 *
 * Jeder Schritt speichert sofort — kein Sitzungsspeicher. Der Preis dafuer
 * ist ein Entwurf in der Liste ab dem ersten Schritt; der Gewinn ist, dass
 * man morgen weitermachen kann, und dieser Test geht denselben Weg.
 */
final class BillingPlanFlowTest extends WebTestCase
{
    use BuildsABillableProperty;
    use ForgetsRateLimits;
    use SignsIn;

    protected function tearDown(): void
    {
        self::removeTheProperty();
        self::removeTestUser();

        parent::tearDown();
    }

    /**
     * Vom leeren Formular ueber die Versammlung bis zur beschlossenen Post.
     *
     * Der Umweg ueber die Beschlussvorlage ist der Grund, warum ein Plan mehr
     * Schritte hat als eine Abrechnung: er muss den Eigentuemern vorliegen,
     * bevor sie darueber beschliessen koennen.
     */
    public function testTheWholeFlowFromTheFirstStepToTheResolution(): void
    {
        $client = self::signedInWith([BillingPermissions::VIEW, BillingPermissions::EDIT]);
        self::buildTheProperty();
        self::alsoPaidIntoTheReserve(Money::fromCents(60000));

        $plan = self::started($client);

        self::assertSame('Wirtschaftsplan 2027', $plan->label());
        self::assertCount(3, $plan->positions(), 'Zwei Kostenzeilen aus 2026 und die Rücklage');

        self::plan($client, $plan, 'positionen', ['1.480,00', '540,00']);
        self::plan($client, $plan, 'ruecklage', ['1.800,00']);
        self::terms($client, $plan);

        $client->request('GET', self::stepUrl($plan, 'vorlage'));
        self::assertSelectorTextContains('.ib-preview', 'Ihr Jahresanteil');

        $client->submitForm('Als Beschlussvorlage herausgeben');
        self::assertResponseRedirects(self::stepUrl($plan, 'vorlage'));

        $proposed = self::reloaded($plan);
        self::assertSame(ResolutionStatus::Proposed, $proposed->stage()->status());
        self::assertTrue($proposed->stage()->wasProposed(), 'Der Tag der Herausgabe steht fest');
        self::assertCount(2, $proposed->documents(), 'Und sie friert ein, was herausgeht');

        self::decide($client, $plan);

        $client->request('GET', self::stepUrl($plan, 'beschluss'));
        $client->submitForm('Beschließen und freigeben');
        self::assertResponseRedirects('/billing/wirtschaftsplaene/'.$plan->id());

        $released = self::reloaded($plan);
        self::assertSame(ResolutionStatus::Released, $released->stage()->status());
        self::assertTrue($released->stage()->wasProposed(), 'Was vorlag, bleibt lesbar');
        self::assertCount(2, $released->documents(), 'Je Einheit ein Schreiben');
        self::assertSame('einstimmig', $released->resolution()->outcome());

        // Und die Liste zeigt ihn auch: ein Zustand, den keine Seite zeichnen
        // kann, ist keiner.
        $list = $client->request('GET', '/billing/wirtschaftsplaene');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Beschlossen', $list->filter('tbody')->text());
    }

    /**
     * Eine herausgegebene Vorlage laesst sich weiter aendern — und ist dann
     * keine mehr.
     *
     * Der Herausgabetag sagt, **was** den Eigentuemern an diesem Tag vorlag.
     * Bliebe er stehen, truege ein spaeter geaendertes Blatt den alten Tag und
     * behauptete etwas, das so nie herausging.
     */
    public function testChangingAProposalWithdrawsIt(): void
    {
        $client = self::signedInWith([BillingPermissions::VIEW, BillingPermissions::EDIT]);
        self::buildTheProperty();
        $plan = self::started($client);

        $client->request('GET', self::stepUrl($plan, 'vorlage'));
        $client->submitForm('Als Beschlussvorlage herausgeben');
        self::assertSame(ResolutionStatus::Proposed, self::reloaded($plan)->stage()->status());

        self::plan($client, self::reloaded($plan), 'positionen', ['2.000,00', '480,00']);

        $changed = self::reloaded($plan);
        self::assertSame(ResolutionStatus::Draft, $changed->stage()->status(), 'Geändert heißt zurückgenommen');
        self::assertFalse($changed->stage()->wasProposed(), 'Und ohne Tag, der etwas Falsches behauptet');

        $first = $changed->positions()[0] ?? null;
        self::assertInstanceOf(PlanPosition::class, $first);
        self::assertSame(200000, $first->entered()->cents(), 'Die Änderung ist trotzdem gespeichert');
    }

    /**
     * Wer nur durchklickt, behaelt seine Vorlage.
     *
     * Ein Schritt, der nichts aendert, darf nichts zuruecknehmen — sonst
     * wuerde ein Blick in die Positionen die Auskunft loeschen, was den
     * Eigentuemern vorlag.
     */
    public function testWalkingThroughWithoutChangingKeepsTheProposal(): void
    {
        $client = self::signedInWith([BillingPermissions::VIEW, BillingPermissions::EDIT]);
        self::buildTheProperty();
        $plan = self::started($client);

        $client->request('GET', self::stepUrl($plan, 'vorlage'));
        $client->submitForm('Als Beschlussvorlage herausgeben');

        self::plan($client, self::reloaded($plan), 'positionen', []);
        self::plan($client, self::reloaded($plan), 'ruecklage', []);

        self::assertSame(ResolutionStatus::Proposed, self::reloaded($plan)->stage()->status());
    }

    /**
     * Der Ablauf zeigt, was vorlag — nicht den heutigen Stand.
     *
     * Sonst stuenden unter „herausgegeben am 30. Oktober" Zahlen, die an
     * diesem Tag niemand gesehen hat. Dass es heute anders aussaehe, sagt der
     * Hinweis daneben.
     */
    public function testTheFlowShowsTheIssuedFiguresAndWarnsAboutDrift(): void
    {
        $client = self::signedInWith([BillingPermissions::VIEW, BillingPermissions::EDIT]);
        self::buildTheProperty();
        $plan = self::started($client);

        $client->request('GET', self::stepUrl($plan, 'vorlage'));
        $client->submitForm('Als Beschlussvorlage herausgeben');

        $before = $client->request('GET', self::stepUrl($plan, 'vorlage'));
        $issued = $before->filter('.ib-plan__sheet')->text();
        self::assertCount(0, $before->filter('.ib-note--warning'), 'Frisch herausgegeben stimmt alles');

        self::growTheFirstUnitTo('95.00');

        $after = $client->request('GET', self::stepUrl($plan, 'vorlage'));

        self::assertSame($issued, $after->filter('.ib-plan__sheet')->text(), 'Dieselben Zahlen wie herausgegeben');
        self::assertCount(1, $after->filter('.ib-note--warning'), 'Aber ein Hinweis, dass sich etwas geändert hat');
    }

    /**
     * Wer gleich auf den Beschluss springt, sieht die Abweichung trotzdem.
     *
     * Die Schritte sind einzeln erreichbar. Stuende der Hinweis nur auf der
     * Vorlage-Seite, koennte jemand einen ueberholten Plan freigeben, ohne ihn
     * je gesehen zu haben.
     */
    public function testTheResolutionStepWarnsAboutDriftAndAsksAgain(): void
    {
        $client = self::signedInWith([BillingPermissions::VIEW, BillingPermissions::EDIT]);
        self::buildTheProperty();
        $plan = self::started($client);

        $client->request('GET', self::stepUrl($plan, 'vorlage'));
        $client->submitForm('Als Beschlussvorlage herausgeben');
        self::growTheFirstUnitTo('95.00');

        // Direkt auf den Beschluss — die Vorlage-Seite bekommt niemand zu sehen.
        $crawler = $client->request('GET', self::stepUrl($plan, 'beschluss'));

        self::assertCount(1, $crawler->filter('.ib-note--warning'), 'Der Hinweis steht auch hier');
        self::assertStringContainsString(
            'Vorgelegte Beträge beschließen',
            $crawler->filter('form[action$="/freigeben"]')->text(),
            'Und der Knopf sagt, was er tut',
        );

        $client->submitForm('Vorgelegte Beträge beschließen');
        self::assertResponseRedirects('/billing/wirtschaftsplaene/'.$plan->id());
        self::assertSame(ResolutionStatus::Released, self::reloaded($plan)->stage()->status());
    }

    /**
     * Und ohne diese Bestaetigung gibt die Anwendung nicht frei.
     *
     * Auch dann nicht, wenn jemand das Formular ohne sie abschickt — ein
     * Knopf, den man umgehen kann, ist keine Sicherung.
     */
    public function testReleasingADriftedPlanWithoutConfirmationIsRefused(): void
    {
        $client = self::signedInWith([BillingPermissions::VIEW, BillingPermissions::EDIT]);
        self::buildTheProperty();
        $plan = self::started($client);

        $crawler = $client->request('GET', self::stepUrl($plan, 'vorlage'));
        $client->submitForm('Als Beschlussvorlage herausgeben');
        self::growTheFirstUnitTo('95.00');

        $client->request('POST', '/billing/wirtschaftsplaene/'.$plan->id().'/freigeben', [
            '_token' => self::tokenOn($crawler),
        ]);

        self::assertSame(ResolutionStatus::Proposed, self::reloaded($plan)->stage()->status(), 'Nichts festgeschrieben');
    }

    /**
     * Der Beschluss nimmt die Vorlage nicht zurueck.
     *
     * Er steht nicht auf ihr — sie geht ja heraus, damit ueber sie
     * beschlossen wird. Wer ihn erfasst, aendert am Blatt nichts.
     */
    public function testRecordingTheResolutionKeepsTheProposal(): void
    {
        $client = self::signedInWith([BillingPermissions::VIEW, BillingPermissions::EDIT]);
        self::buildTheProperty();
        $plan = self::started($client);

        $client->request('GET', self::stepUrl($plan, 'vorlage'));
        $client->submitForm('Als Beschlussvorlage herausgeben');

        self::decide($client, self::reloaded($plan));

        self::assertSame(ResolutionStatus::Proposed, self::reloaded($plan)->stage()->status());
    }

    /** Ein beschlossener Plan laesst sich nicht mehr bearbeiten. */
    public function testAResolvedPlanIsClosed(): void
    {
        $client = self::signedInWith([BillingPermissions::VIEW, BillingPermissions::EDIT]);
        self::buildTheProperty();
        $plan = self::started($client);

        self::terms($client, $plan);
        self::decide($client, $plan);
        $client->request('GET', self::stepUrl($plan, 'beschluss'));
        $client->submitForm('Beschließen und freigeben');

        $client->request('GET', self::stepUrl(self::reloaded($plan), 'positionen'));

        self::assertResponseStatusCodeSame(403);
    }

    /** Eine Zeile kommt dazu und geht wieder — ohne den Schritt zu verlassen. */
    public function testARowCanBeAddedAndRemovedWithoutLeavingTheStep(): void
    {
        $client = self::signedInWith([BillingPermissions::VIEW, BillingPermissions::EDIT]);
        self::buildTheProperty();
        $plan = self::started($client);

        $client->request('GET', self::stepUrl($plan, 'positionen'));
        $client->submitForm('Zeile hinzufügen');

        self::assertResponseRedirects(self::stepUrl($plan, 'positionen'), message: 'Anlegen bleibt auf dem Schritt');
        self::assertCount(4, self::reloaded($plan)->positions());

        $crawler = $client->request('GET', self::stepUrl($plan, 'positionen'));
        $added = $crawler->filter('[data-plan-row]')->last()->attr('data-plan-row');
        self::assertIsString($added);

        $client->request('POST', self::stepUrl($plan, 'positionen'), [
            '_token' => self::tokenOn($crawler),
            'remove' => $added,
        ]);

        self::assertCount(3, self::reloaded($plan)->positions());
    }

    /**
     * Die Ruecklage laesst sich nicht entfernen.
     *
     * Ueber sie wird nach § 28 Abs. 1 WEG eigens beschlossen; ein Plan ohne
     * sie waere einer, in dem jemand vergessen hat, sie zu beantragen. Wer
     * nichts zufuehren will, plant null.
     */
    public function testTheReserveRowCannotBeRemoved(): void
    {
        $client = self::signedInWith([BillingPermissions::VIEW, BillingPermissions::EDIT]);
        self::buildTheProperty();
        $plan = self::started($client);

        $reserve = null;

        foreach ($plan->positions() as $position) {
            if ($position->isReserve()) {
                $reserve = $position->id();
            }
        }

        self::assertIsString($reserve);

        $crawler = $client->request('GET', self::stepUrl($plan, 'ruecklage'));
        $client->request('POST', self::stepUrl($plan, 'ruecklage'), [
            '_token' => self::tokenOn($crawler),
            'remove' => $reserve,
        ]);

        self::assertCount(3, self::reloaded($plan)->positions(), 'Sie steht noch da');
    }

    /** Ein Entwurf laesst sich loeschen — und gibt seine Quellen wieder frei. */
    public function testADraftCanBeDeleted(): void
    {
        $client = self::signedInWith([BillingPermissions::VIEW, BillingPermissions::EDIT]);
        self::buildTheProperty();
        $plan = self::started($client);

        self::assertNotSame([], self::sources()->usedByAPlan([self::aKeyUsedByThePlan()->id()]));

        $crawler = $client->request('GET', self::stepUrl($plan, 'positionen'));
        $client->request('POST', '/billing/wirtschaftsplaene/'.$plan->id().'/loeschen', [
            '_token' => self::tokenOn($crawler),
        ]);

        self::assertResponseRedirects('/billing/wirtschaftsplaene');
        self::assertSame(0, self::plans()->countMatching(PlanFilter::none()));
        self::assertSame([], self::sources()->usedByAPlan([self::aKeyUsedByThePlan()->id()]), 'Die Sperre ist weg');
    }

    /**
     * Was in einem Wirtschaftsplan steckt, verschwindet nicht mehr.
     *
     * Ein eigener Schluessel, den ein Plan benutzt, laesst sich nicht
     * loeschen. Der Fremdschluessel haelt zwar ohnehin — aber als
     * Datenbankfehler, und ein 500er ist keine Antwort auf eine Frage, die
     * man verstehen kann.
     */
    public function testAKeyUsedByAPlanCannotBeDeleted(): void
    {
        $client = self::signedInWith([BillingPermissions::VIEW, BillingPermissions::EDIT]);
        self::buildTheProperty();
        $own = self::anOwnKey();
        $plan = self::started($client);

        $position = $plan->positions()[0] ?? null;
        self::assertInstanceOf(PlanPosition::class, $position);
        $position->distributeBy($own->id(), $own->name(), $own->kind()->value);
        self::plans()->save($plan);
        self::holdTheSources($plan);

        $this->expectException(UsedByAPlan::class);
        self::maintain()->drop($own);
    }

    /** Ohne Bearbeitungsrecht gibt es den Ablauf nicht. */
    public function testTheFlowNeedsTheEditPermission(): void
    {
        $client = self::signedInWith([BillingPermissions::VIEW]);
        self::buildTheProperty();

        $client->request('GET', '/billing/wirtschaftsplaene/neu');

        self::assertResponseStatusCodeSame(403);
    }

    /** Ein Objekt ohne WEG-Verwaltung steht nicht zur Wahl. */
    public function testOnlyCondominiumPropertiesAreOffered(): void
    {
        $client = self::signedInWith([BillingPermissions::VIEW, BillingPermissions::EDIT]);
        self::buildTheProperty();

        $crawler = $client->request('GET', '/billing/wirtschaftsplaene/neu');

        self::assertSame(
            1,
            $crawler->filter('#property option[value="'.self::PROPERTY_NUMBER.'"]')->count(),
            'Das WEG-Objekt steht da',
        );
    }

    protected static function testEmail(): string
    {
        return 'planklick@example.org';
    }

    private static function started(KernelBrowser $client): Plan
    {
        $client->request('GET', '/billing/wirtschaftsplaene/neu');
        $client->submitForm('Weiter', [
            'property' => (string) self::PROPERTY_NUMBER,
            'fiscalYear' => '2027',
            'label' => 'Wirtschaftsplan 2027',
        ]);

        $plans = self::plans()->matching(PlanFilter::none(), Page::of(1, 10));
        self::assertCount(1, $plans);

        return $plans[0];
    }

    /**
     * Einen Zeilenschritt ausfuellen und weitergehen.
     *
     * @param list<string> $amounts in der Reihenfolge der Zeilen
     */
    private static function plan(KernelBrowser $client, Plan $plan, string $step, array $amounts): void
    {
        $crawler = $client->request('GET', self::stepUrl($plan, $step));
        $values = [];

        foreach ($crawler->filter('[data-plan-row]') as $at => $row) {
            $id = $row instanceof DOMElement ? $row->getAttribute('data-plan-row') : '';

            if ('' !== $id && isset($amounts[$at])) {
                $values['rows['.$id.'][amount]'] = $amounts[$at];
            }
        }

        $client->submitForm('Weiter', $values);
        self::assertResponseRedirects();
    }

    private static function terms(KernelBrowser $client, Plan $plan): void
    {
        $client->request('GET', self::stepUrl($plan, 'vorschuesse'));
        $client->submitForm('Weiter', [
            'interval' => 'monthly',
            'firstDueOn' => '2027-01-01',
        ]);
        self::assertResponseRedirects(self::stepUrl($plan, 'vorlage'));
    }

    private static function decide(KernelBrowser $client, Plan $plan): void
    {
        $client->request('GET', self::stepUrl($plan, 'beschluss'));
        $client->submitForm('Speichern', [
            'decidedOn' => '2026-11-14',
            'outcome' => 'einstimmig',
            'decisionNumber' => '2026/04',
        ]);
        self::assertResponseRedirects(self::stepUrl($plan, 'beschluss'));
    }

    private static function stepUrl(Plan $plan, string $step): string
    {
        return '/billing/wirtschaftsplaene/'.$plan->id().'/bearbeiten/'.$step;
    }

    private static function reloaded(Plan $plan): Plan
    {
        $found = self::plans()->byId($plan->id());
        self::assertInstanceOf(Plan::class, $found);

        return $found;
    }

    private static function growTheFirstUnitTo(string $area): void
    {
        $properties = self::getContainer()->get(PropertyRepository::class);
        self::assertInstanceOf(PropertyRepository::class, $properties);
        $property = $properties->byId(self::propertyId());
        self::assertInstanceOf(Property::class, $property);

        $unit = $property->units()[0] ?? null;
        self::assertInstanceOf(Unit::class, $unit);
        $unit->measure(Measures::of($area, null, null));
        $properties->save($property);
    }

    private static function tokenOn(Crawler $crawler): string
    {
        $token = $crawler->filter('main input[name="_token"]')->first();

        return 0 === $token->count() ? '' : (string) $token->attr('value');
    }

    private static function aKeyUsedByThePlan(): DistributionKey
    {
        foreach (self::allKeys() as $key) {
            if (DistributionKeyKind::Area === $key->kind()) {
                return $key;
            }
        }

        self::fail('Kein Flächenschlüssel');
    }

    private static function anOwnKey(): DistributionKey
    {
        $key = new DistributionKey(self::propertyId(), 'Prüfanteile Plan', DistributionKeyKind::Fixed);
        self::keys()->save($key);

        return $key;
    }

    private static function holdTheSources(Plan $plan): void
    {
        $positions = self::getContainer()->get(PlanPositions::class);
        self::assertInstanceOf(PlanPositions::class, $positions);
        $positions->holdSources($plan);
    }

    /**
     * @return list<DistributionKey>
     */
    private static function allKeys(): array
    {
        return self::keys()->forProperty(self::propertyId());
    }

    private static function keys(): DistributionKeyRepository
    {
        $found = self::getContainer()->get(DistributionKeyRepository::class);
        self::assertInstanceOf(DistributionKeyRepository::class, $found);

        return $found;
    }

    private static function maintain(): MaintainDistributionKeys
    {
        $found = self::getContainer()->get(MaintainDistributionKeys::class);
        self::assertInstanceOf(MaintainDistributionKeys::class, $found);

        return $found;
    }

    private static function sources(): PlanSources
    {
        $found = self::getContainer()->get(PlanSources::class);
        self::assertInstanceOf(PlanSources::class, $found);

        return $found;
    }

    private static function plans(): PlanRepository
    {
        $found = self::getContainer()->get(PlanRepository::class);
        self::assertInstanceOf(PlanRepository::class, $found);

        return $found;
    }
}
