<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Module\Party\Domain\Addresses;
use App\Module\Party\Domain\AddressKind;
use App\Module\Party\Domain\ContactDetails;
use App\Module\Party\Domain\Party;
use App\Module\Party\Domain\PartyFilter;
use App\Module\Party\Domain\PartyKind;
use App\Module\Party\Domain\PartyRole;
use App\Module\Party\Domain\PartyRoles;
use App\Module\Party\Domain\PostalAddress;
use App\Shared\Contact\Email;
use App\Shared\Ui\Page;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Was ein Portalnutzer sieht — und was nicht.
 *
 * Die zweite Zusicherung des Moduls: **er sieht seine Daten und nur seine.**
 * Es gibt im Portal keine Kennung in der Adresszeile, also auch nichts
 * hochzuzaehlen; geprueft wird trotzdem, dass die Daten eines Fremden nicht
 * erscheinen — der Weg dorthin waere sonst nur eine Zeile entfernt.
 */
final class PortalDataTest extends WebTestCase
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

    /** Der Eigentümer sieht seine Stammdaten, seine Objekte und seine Einheiten. */
    public function testAnOwnerSeesWhatIsHis(): void
    {
        $client = self::asParty(self::ownerId(...));

        $data = $client->request('GET', '/portal/daten');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Paula Prüfer', $data->text());
        self::assertStringContainsString('Kontrollweg 9', $data->text());

        $properties = $client->request('GET', '/portal/objekte');
        self::assertStringContainsString('Prüfweg', $properties->text());

        $units = $client->request('GET', '/portal/einheiten');
        self::assertStringContainsString('WE 1', $units->text());
        self::assertStringContainsString('WE 2', $units->text());
    }

    /**
     * Ein Fremder sieht davon nichts.
     *
     * Dieselben Seiten, dieselbe Anmeldung, eine andere Partei — und die
     * Objekte des Eigentümers tauchen nirgends auf. Das ist die Probe darauf,
     * dass wirklich die Sitzung entscheidet und nicht die Seite.
     */
    public function testAStrangerSeesNothingOfIt(): void
    {
        $client = self::asParty(static fn (): string => self::aStranger()->id());

        $properties = $client->request('GET', '/portal/objekte');
        self::assertResponseIsSuccessful();
        self::assertStringNotContainsString('Prüfweg', $properties->text());

        $units = $client->request('GET', '/portal/einheiten');
        self::assertStringNotContainsString('WE 1', $units->text());

        $tenancies = $client->request('GET', '/portal/mietverhaeltnisse');
        self::assertStringNotContainsString('Prüfweg', $tenancies->text());
    }

    /** Ein unbekannter Bereich fällt auf den ersten zurück — Eingabe, kein Fehler. */
    public function testAnUnknownAreaIsNotAnError(): void
    {
        $client = self::asParty(self::ownerId(...));

        $client->request('GET', '/portal/einheiten');
        self::assertResponseIsSuccessful();

        // Was die Aufzählung in der Route nicht kennt, gibt es gar nicht.
        $client->request('GET', '/portal/geheimes');
        self::assertResponseStatusCodeSame(404);
    }

    /** Und /portal führt auf den ersten Bereich. */
    public function testTheEntranceLeadsSomewhere(): void
    {
        $client = self::asParty(self::ownerId(...));

        $client->request('GET', '/portal');

        self::assertResponseRedirects('/portal/daten');
    }

    /**
     * Der Taschenrechner der Verwaltung steht hier nicht.
     *
     * Er ist ein Werkzeug fuers Erfassen von Betraegen, und Betraege erfasst
     * hier niemand. Das Portal hat seine eigene Kopfzeile — die Zusicherung
     * steht hier, damit er nicht hineinrutscht, wenn die beiden einmal
     * zusammengelegt werden.
     */
    public function testThePortalHeaderCarriesNoCalculator(): void
    {
        $client = self::asParty(self::ownerId(...));

        $client->request('GET', '/portal/daten');

        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('[data-calculator]');
    }

    protected static function testEmail(): string
    {
        return 'portaldaten@example.org';
    }

    /**
     * Client, Stammdaten, Anmeldung — in dieser Reihenfolge.
     *
     * Der Kernel darf nur einmal hochfahren, und die Partei entsteht erst beim
     * Bauen des Objekts. Die Kennung kommt darum als Aufruf und nicht als
     * Wert: zum Zeitpunkt des Aufrufs gibt es sie noch gar nicht.
     *
     * @param callable(): string $partyId
     */
    private static function asParty(callable $partyId): KernelBrowser
    {
        $client = self::createClient();
        self::buildTheProperty();
        self::signInForParty($client, $partyId());

        return $client;
    }

    private static function ownerId(): string
    {
        return self::anOwner()->id();
    }

    /**
     * Jemand, der mit dem Objekt nichts zu tun hat.
     *
     * Gesucht, bevor angelegt — wie beim Eigentümer: ein Lauf, der vorzeitig
     * abbricht, lässt seinen Kontakt stehen.
     */
    private static function aStranger(): Party
    {
        foreach (self::parties()->matching(PartyFilter::none(), Page::of(1, 500)) as $known) {
            if (99009 === $known->reference()) {
                return $known;
            }
        }

        $stranger = new Party(
            99009,
            PartyKind::Person,
            'Fremd',
            'Frieda',
            PartyRoles::of([PartyRole::Other]),
            Addresses::of([PostalAddress::of(AddressKind::Street, 'Fremdweg 1', '50667', 'Köln')]),
            ContactDetails::of([Email::fromString('frieda@example.org')]),
        );
        self::parties()->save($stranger);

        return $stranger;
    }
}
