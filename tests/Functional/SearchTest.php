<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Module\Billing\Domain\BillingPermissions;
use App\Module\Finance\Domain\FinancePermissions;
use App\Module\Party\Domain\PartyPermissions;
use App\Module\Property\Domain\PropertyPermissions;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Die eine Suche, ueber alle Module.
 *
 * Zwei Zusagen stehen hier. **Man findet ueber alles**, was an einem
 * Datensatz steht — nicht nur ueber seine Nummer: wer jemanden sucht, hat die
 * Strasse im Kopf und nicht die Kundennummer. Und **die Suche ist keine
 * Hintertuer an den Rechten vorbei**: was jemand nicht oeffnen darf, taucht
 * auch nicht unter seinen Treffern auf.
 */
final class SearchTest extends WebTestCase
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

    /** Ueber die Strasse — die Nummer hat niemand im Kopf. */
    public function testAPartyIsFoundByItsStreet(): void
    {
        $client = self::signedInWith([PartyPermissions::VIEW]);
        self::buildTheProperty();

        $client->request('GET', '/suche', ['q' => 'Kontrollweg']);

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Paula Prüfer');
    }

    /**
     * Ueber die Kontonummer, mit den Leerzeichen, in denen sie dasteht.
     *
     * Gespeichert steht die IBAN am Stueck. Wer sie von einem Kontoauszug
     * abtippt, tippt Vierergruppen — und soll trotzdem sein Objekt finden.
     */
    public function testAPropertyIsFoundByItsIban(): void
    {
        $client = self::signedInWith([PropertyPermissions::VIEW]);
        self::buildTheProperty();

        $client->request('GET', '/suche', ['q' => 'DE02 1203 0000']);

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Abrechnungsobjekt Prüfweg');
    }

    /** Ein Zeichen ist keine Frage, sondern ein Tastendruck. */
    public function testOneCharacterSearchesNothing(): void
    {
        $client = self::signedInWith([PartyPermissions::VIEW]);
        self::buildTheProperty();

        $client->request('GET', '/suche', ['q' => 'K']);

        self::assertResponseIsSuccessful();
        self::assertSelectorTextNotContains('body', 'Paula Prüfer');
    }

    /**
     * Ohne das Recht steht der Bereich nicht in den Treffern.
     *
     * Geprueft mit einem Konto, das die Objekte sehen darf und die Stammdaten
     * nicht: der Suchbegriff trifft beide, und nur eine Gruppe kommt zurueck.
     */
    public function testWhatSomeoneMayNotOpenIsNotFoundEither(): void
    {
        $client = self::signedInWith([PropertyPermissions::VIEW]);
        self::buildTheProperty();

        $client->request('GET', '/suche', ['q' => 'Prüf']);

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Abrechnungsobjekt Prüfweg');
        self::assertSelectorTextNotContains('body', 'Paula Prüfer');
    }

    /** Dieselbe Grenze in der Vorschlagsliste — sie fragt dieselben Quellen. */
    public function testTheSuggestionsObeyTheSameRights(): void
    {
        $client = self::signedInWith([PropertyPermissions::VIEW]);
        self::buildTheProperty();

        $client->request('GET', '/suche/vorschlaege', ['q' => 'Prüf']);

        $body = (string) $client->getResponse()->getContent();

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Abrechnungsobjekt', $body);
        self::assertStringNotContainsString('Paula', $body);
    }

    /**
     * Ein Prozentzeichen ist Eingabe und kein Platzhalter.
     *
     * „Prüf%r" darf die Kostenart „Prüfsteuer" **nicht** finden. Als
     * Platzhalter gelesen faende es sie — „prüf", irgendetwas, „r" —, als
     * Eingabe gelesen sucht es die Zeichenfolge „prüfr", und die gibt es
     * nirgends. Faende der Test sie doch, staende fest, dass das Zeichen bis
     * in die Abfrage durchgereicht wurde; und dann faende „%" alles, was die
     * Anwendung kennt.
     *
     * Geprueft an einer Quelle, die ueber den Filter ihres Moduls sucht: dort
     * geht der Text durch eine zweite Hand, und die kann ihn wieder roh
     * weiterreichen.
     */
    public function testAPercentSignIsInputAndNotAWildcard(): void
    {
        $client = self::signedInWith([FinancePermissions::VIEW]);
        self::buildTheProperty();

        $client->request('GET', '/suche', ['q' => 'Prüfsteuer']);
        self::assertSelectorTextContains('body', 'Prüfsteuer', 'Ohne Platzhalter gefunden');

        $client->request('GET', '/suche', ['q' => 'Prüf%r']);
        self::assertSelectorTextNotContains('body', 'Prüfsteuer', 'Mit Platzhalter nicht');
    }

    /** Nichts Getipptes ist kein Fehler, sondern der Zustand vor der Frage. */
    public function testTheSuggestionsAnswerEmptyWithoutATerm(): void
    {
        $client = self::signedInWith([BillingPermissions::VIEW]);

        $client->request('GET', '/suche/vorschlaege', ['q' => '']);

        self::assertResponseIsSuccessful();
        self::assertSame('{"groups":[]}', (string) $client->getResponse()->getContent());
    }

    protected static function testEmail(): string
    {
        return 'suche@example.org';
    }
}
