<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Module\Billing\Application\DraftSelection;
use App\Module\Billing\Application\ReleaseStatement;
use App\Module\Billing\Domain\BillingPermissions;
use App\Module\Billing\Domain\Statement;
use App\Module\Billing\Domain\StatementRepository;
use DateTimeImmutable;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Wie eine Abrechnung angesehen wird.
 *
 * Ein Schreiben zur Zeit und immer im selben Rahmen: der Entwurf in Schritt 5
 * und die freigegebene Abrechnung auf demselben Schritt. Ein Objekt mit zwoelf
 * Einheiten ergibt zwei Dutzend Schreiben — untereinander waeren sie eine
 * Seite, die niemand prueft, und zwei Gestalten fuer dieselbe Sache waeren
 * zwei Sachen.
 */
final class BillingPreviewTest extends WebTestCase
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

    /** Der Entwurf: Schritt 5, eine Wahl, ein Schreiben. */
    public function testTheDraftShowsOneLetterAtATime(): void
    {
        $client = self::signedInWith([BillingPermissions::VIEW, BillingPermissions::EDIT]);
        $statement = self::aChosenDraft();

        $crawler = $client->request(
            'GET',
            '/billing/abrechnungen/'.$statement->id().'/bearbeiten/vorschau',
        );

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('select[name="empfaenger"]', 'Oben wird der Empfänger gewählt');
        self::assertGreaterThan(1, $crawler->filter('select[name="empfaenger"] option')->count());
        self::assertCount(1, $crawler->filter('.ib-preview'), 'Ein Schreiben und nicht alle');
    }

    /** Und die Wahl wirkt: ein anderer Schlüssel, ein anderes Schreiben. */
    public function testChoosingARecipientShowsThatLetter(): void
    {
        $client = self::signedInWith([BillingPermissions::VIEW, BillingPermissions::EDIT]);
        $statement = self::aChosenDraft();
        $url = '/billing/abrechnungen/'.$statement->id().'/bearbeiten/vorschau';

        $first = $client->request('GET', $url);
        $another = $first->filter('select[name="empfaenger"] option')->eq(1)->attr('value');
        self::assertIsString($another);

        $second = $client->request('GET', $url.'?empfaenger='.urlencode($another));

        self::assertSame($another, $second->filter('select[name="empfaenger"] option[selected]')->attr('value'));
        self::assertNotSame(
            $first->filter('.ib-preview__who')->text(),
            $second->filter('.ib-preview__who')->text(),
            'Die Wahl zeigt ein anderes Schreiben',
        );
    }

    /**
     * Die freigegebene Abrechnung steht im selben Rahmen.
     *
     * Links die Schritte, rechts der letzte — und keiner davon ein Link:
     * geaendert wird hier nichts mehr, und ein Link, der zuverlaessig in eine
     * Absage fuehrt, ist schlechter als keiner.
     */
    public function testTheReleasedStatementOpensOnTheLastStep(): void
    {
        $client = self::signedInWith([BillingPermissions::VIEW]);
        $statement = self::aReleasedStatement();

        $crawler = $client->request('GET', '/billing/abrechnungen/'.$statement->id());

        self::assertResponseIsSuccessful();
        self::assertCount(5, $crawler->filter('.ib-flow__step'), 'Alle fünf Schritte stehen da');
        self::assertSame(
            'Vorschau',
            $crawler->filter('.ib-flow__step.is-current')->text(),
            'Geöffnet wird auf dem Prüfschritt',
        );
        self::assertCount(0, $crawler->filter('.ib-flow__step a'), 'Zurückspringen gibt es nicht');
        self::assertSelectorExists('select[name="empfaenger"]');
        self::assertCount(1, $crawler->filter('.ib-preview'));
    }

    /**
     * Ein Entwurf laesst sich aus der Liste wegwerfen.
     *
     * Die Route gab es von Anfang an, den Knopf dazu nicht — und eine
     * Handlung, die niemand ausloesen kann, ist keine. Freigegebene
     * Abrechnungen haben keinen: es gibt kein `billing.delete`.
     */
    public function testADraftCanBeThrownAwayFromTheList(): void
    {
        $client = self::signedInWith([BillingPermissions::VIEW, BillingPermissions::EDIT]);
        $statement = self::aChosenDraft();

        $crawler = $client->request('GET', '/billing/abrechnungen');

        self::assertResponseIsSuccessful();
        self::assertCount(
            1,
            $crawler->filter('[data-modal-open="billing-delete-'.$statement->id().'"]'),
            'Der Entwurf trägt seinen Löschen-Knopf',
        );

        $client->submit($crawler->filter('form[action$="/'.$statement->id().'/loeschen"]')->form());

        self::assertResponseRedirects('/billing/abrechnungen');
        self::assertNull(self::statements()->byId($statement->id()));
    }

    /**
     * Die Liste laesst sich einschraenken — und die Zahl darueber stimmt.
     *
     * Eine Filterleiste ohne Felder ist eine Leiste, kein Filter. Und eine
     * Anzahl, die nicht zur gezeigten Liste gehoert, ist schlimmer als keine.
     */
    public function testTheListCanBeNarrowed(): void
    {
        $client = self::signedInWith([BillingPermissions::VIEW, BillingPermissions::EDIT]);
        $draft = self::aChosenDraft();

        $crawler = $client->request('GET', '/billing/abrechnungen');
        self::assertResponseIsSuccessful();

        foreach (['q', 'objekt', 'jahr', 'zustand'] as $field) {
            self::assertCount(1, $crawler->filter('.ib-index__filters [name="'.$field.'"]'), $field.' fehlt');
        }

        $found = $client->request('GET', '/billing/abrechnungen?zustand=draft&objekt='.self::PROPERTY_NUMBER);
        self::assertStringContainsString(
            (string) $draft->number(),
            $found->filter('tbody')->text(),
            'Der Entwurf steht in seiner eigenen Auswahl',
        );

        $empty = $client->request('GET', '/billing/abrechnungen?zustand=released&objekt='.self::PROPERTY_NUMBER);
        self::assertCount(0, $empty->filter('tbody tr'), 'Freigegeben gibt es hier keine');
        self::assertStringContainsString(
            'Keine',
            $empty->filter('.ib-index__count')->text(),
            'Die Anzahl gehört zur gezeigten Liste',
        );

        $elsewhere = $client->request('GET', '/billing/abrechnungen?objekt=1');
        self::assertCount(0, $elsewhere->filter('tbody tr'), 'Ein fremdes Objekt zeigt nichts');
    }

    /**
     * Eine faellige Korrektur steht am Menuepunkt.
     *
     * Sie entsteht von selbst — niemand hat sie angelegt, jemand hat anderswo
     * eine Zahl geaendert. Ohne Abzeichen erfuehre davon nur, wer zufaellig
     * auf die Uebersicht geht, und die Frist laeuft weiter.
     */
    public function testAPendingCorrectionShowsAtTheMenu(): void
    {
        $client = self::signedInWith([BillingPermissions::VIEW]);
        $statement = self::aReleasedStatement();

        $quiet = $client->request('GET', '/billing');
        self::assertCount(0, $quiet->filter('.ib-nav__badge'), 'Ohne Fälliges kein Abzeichen');

        // Der Kasten steht trotzdem da und sagt, dass nichts ansteht: ein
        // Kasten, der nur im Ernstfall erscheint, lässt sonst offen, ob
        // gerade nichts anliegt oder niemand nachgesehen hat.
        self::assertCount(5, $quiet->filter('.ib-overview__panel'), 'Alle Auswertungen stehen immer da');
        self::assertStringContainsString(
            'Alles aktuell',
            $quiet->filter('.ib-overview__panel')->first()->text(),
            'Der Korrekturkasten sagt, dass nichts ansteht',
        );
        self::assertCount(0, $quiet->filter('form[action$="/korrigieren"]'), 'Nichts zu korrigieren, kein Knopf');

        self::raiseTheCosts();

        $loud = $client->request('GET', '/billing');
        self::assertCount(5, $loud->filter('.ib-overview__panel'));
        self::assertStringContainsString(
            'betroffen',
            $loud->filter('.ib-overview__panel')->first()->text(),
            'Die fällige Korrektur steht im Kasten',
        );
        // Der Knopf hängt an `billing.edit`, dieses Konto hat nur `view`:
        // ein Knopf, der zuverlässig in eine Absage führt, ist schlechter
        // als keiner.
        self::assertCount(0, $loud->filter('form[action$="/korrigieren"]'));
        $badge = $loud->filter('.ib-nav__badge');

        self::assertSame('1', $badge->filter('[aria-hidden="true"]')->text(), 'Eine Abrechnung ist fällig');
        self::assertStringContainsString(
            'eine Abrechnung',
            $badge->text(),
            'Und die Vorlesestimme hört nicht nur die Zahl',
        );
        self::assertNotSame([], $statement->documents());
    }

    /**
     * Der Knopf zaehlt neu — ohne ihn stuende die Zahl bis zur Anmeldung.
     *
     * Gezaehlt wird bei der Anmeldung und beim Oeffnen der Uebersicht. Wer
     * gerade nebenan einen Betrag geaendert hat, kommt sonst auf eine Seite,
     * die noch den Stand von vorhin zeigt.
     */
    public function testCheckingAgainCountsAfresh(): void
    {
        $client = self::signedInWith([BillingPermissions::VIEW]);
        self::aReleasedStatement();

        $page = $client->request('GET', '/billing');
        self::assertCount(0, $page->filter('.ib-nav__badge'));

        self::raiseTheCosts();

        // Eine beliebige andere Seite zählt nicht nach: das ist der Sinn der
        // Sache. Die Zahl ist der letzte bekannte Stand, nicht die Wahrheit.
        $client->request('GET', '/');
        self::assertSelectorNotExists('.ib-nav__badge', 'Andere Seiten rechnen nicht nach');

        $client->submit($page->filter('form[action$="/pruefen"]')->form());
        $client->followRedirect();

        self::assertSelectorExists('.ib-nav__badge');
        self::assertSelectorTextContains('.ib-flash', 'Korrektur fällig');
    }

    protected static function testEmail(): string
    {
        return 'ansicht@example.org';
    }

    private static function aChosenDraft(): Statement
    {
        self::buildTheProperty();

        $statements = self::statements();
        $statement = new Statement($statements->nextNumber(), self::propertyId(), self::PROPERTY_NUMBER, self::aFiscalYear(2026));
        $statements->save($statement);

        $selection = self::getContainer()->get(DraftSelection::class);
        self::assertInstanceOf(DraftSelection::class, $selection);
        $selection->keep($statement, self::allCostYears(), self::allPayments());

        return $statement;
    }

    private static function aReleasedStatement(): Statement
    {
        $statement = self::aChosenDraft();
        $release = self::getContainer()->get(ReleaseStatement::class);
        self::assertInstanceOf(ReleaseStatement::class, $release);
        $release->release($statement, new DateTimeImmutable('2027-03-01'));

        return $statement;
    }

    /** @return list<string> */
    private static function allCostYears(): array
    {
        $ids = [];

        foreach (self::items()->forProperty(self::propertyId()) as $item) {
            foreach ($item->years()->all() as $year) {
                $ids[] = $year->id();
            }
        }

        return $ids;
    }

    /** @return list<string> */
    private static function allPayments(): array
    {
        return array_map(
            static fn (object $payment): string => $payment->id(),
            self::paid()->forYear(self::unitIds(), 2026),
        );
    }

    private static function statements(): StatementRepository
    {
        $statements = self::getContainer()->get(StatementRepository::class);
        self::assertInstanceOf(StatementRepository::class, $statements);

        return $statements;
    }
}
