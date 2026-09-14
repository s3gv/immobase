<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Module\Party\Domain\Addresses;
use App\Module\Party\Domain\AddressKind;
use App\Module\Party\Domain\ContactDetails;
use App\Module\Party\Domain\Party;
use App\Module\Party\Domain\PartyKind;
use App\Module\Party\Domain\PartyPermissions;
use App\Module\Party\Domain\PartyRepository;
use App\Module\Party\Domain\PartyRole;
use App\Module\Party\Domain\PartyRoles;
use App\Module\Party\Domain\PostalAddress;
use App\Shared\Contact\Email;
use App\Tests\Module\Party\Fixture\StubPartyLinkSource;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Die Uebersicht der Stammdaten samt Filtern.
 *
 * Der Rollenfilter fragt eine Spalte ab, die zu einem eingebetteten Wertobjekt
 * gehoert. Genau daran ist er schon einmal gescheitert — sichtbar erst im
 * Browser, weil es keinen Test gab.
 */
final class PartyIndexTest extends WebTestCase
{
    use SignsIn;

    protected function tearDown(): void
    {
        self::removeTestParties();
        self::removeTestUser();

        parent::tearDown();
    }

    /**
     * @return iterable<string, array{string, list<string>, list<string>}>
     */
    public static function filters(): iterable
    {
        yield 'ohne Filter' => ['', ['Erika Muster', 'Musterbau GmbH'], []];
        yield 'nur Mieter' => ['?role=tenant', ['Erika Muster'], ['Musterbau GmbH']];
        yield 'nur Eigentümer' => ['?role=owner', ['Musterbau GmbH'], []];
        yield 'Suche nach Name' => ['?q=musterbau', ['Musterbau GmbH'], ['Erika Muster']];
        yield 'Suche nach Vorname' => ['?q=erika', ['Erika Muster'], ['Musterbau GmbH']];
        yield 'Suche nach Referenznummer' => ['?q=90001', ['Erika Muster'], ['Musterbau GmbH']];
        yield 'unbekannte Rolle schränkt nicht ein' => ['?role=quatsch', ['Erika Muster', 'Musterbau GmbH'], []];
        yield 'Seite jenseits der letzten' => ['?page=99', ['Erika Muster'], []];
        // Aus der Adresszeile kommt Eingabe, kein Fehler: leer und Unsinn
        // sind beide „keine Angabe" und nicht 400.
        yield 'leere Seitenzahl' => ['?page=', ['Erika Muster'], []];
        yield 'Seitenzahl, die keine ist' => ['?page=zwei', ['Erika Muster'], []];
    }

    /**
     * @param list<string> $expected
     * @param list<string> $absent
     */
    #[DataProvider('filters')]
    public function testShowsWhatTheFilterAsksFor(string $query, array $expected, array $absent): void
    {
        $client = self::signedInClient();
        self::givenTwoParties();

        $client->request('GET', '/stammdaten'.$query);

        self::assertResponseIsSuccessful();
        $html = (string) $client->getResponse()->getContent();

        foreach ($expected as $name) {
            self::assertStringContainsString($name, $html, \sprintf('"%s" fehlt bei %s', $name, $query));
        }

        foreach ($absent as $name) {
            self::assertStringNotContainsString($name, $html, \sprintf('"%s" gehört nicht zu %s', $name, $query));
        }
    }

    /**
     * Eine leere Liste sagt, warum sie leer ist.
     *
     * „Noch keine Stammdaten erfasst" waere gelogen, wenn zwei davon
     * dastehen und nur keiner zur Suche passt — der Leser suchte den Fehler
     * dann bei den Daten statt beim Filter.
     */
    public function testAnEmptyListSaysWhetherTheFilterIsToBlame(): void
    {
        $client = self::signedInClient();
        self::givenTwoParties();

        $client->request('GET', '/stammdaten?q=gibtesnicht');
        $filtered = (string) $client->getResponse()->getContent();

        self::assertStringContainsString('Kein Eintrag passt zu dieser Suche', $filtered);
        self::assertStringNotContainsString('Noch keine Stammdaten erfasst', $filtered);

        $client->request('GET', '/stammdaten');
        $all = (string) $client->getResponse()->getContent();

        self::assertStringNotContainsString('Kein Eintrag passt zu dieser Suche', $all, 'Ungefiltert nicht');
    }

    /**
     * Ein Eigentuemer, der auch mietet, taucht in beiden Filtern auf — das ist
     * der Grund, warum ein Datensatz mehrere Rollen tragen darf.
     */
    public function testSomeoneWithTwoRolesAppearsUnderBoth(): void
    {
        $client = self::signedInClient();
        self::party(90003, 'Doppel', 'Rolf', [PartyRole::Tenant, PartyRole::Owner]);

        foreach (['tenant', 'owner'] as $role) {
            $client->request('GET', '/stammdaten?role='.$role);

            self::assertStringContainsString('Rolf Doppel', (string) $client->getResponse()->getContent());
        }
    }

    public function testOpensASingleRecordByItsReference(): void
    {
        $client = self::signedInClient();
        self::givenTwoParties();

        $client->request('GET', '/stammdaten/90001');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.ib-heading', 'Erika Muster');
    }

    public function testAnUnknownReferenceIsNotFound(): void
    {
        $client = self::signedInClient();
        $client->request('GET', '/stammdaten/99999');

        self::assertResponseStatusCodeSame(404);
    }

    public function testDeletingRemovesTheRecord(): void
    {
        $client = self::signedInClient();
        self::givenTwoParties();

        $crawler = $client->request('GET', '/stammdaten/90001');
        $client->submit($crawler->filter('form[action*="loeschen"]')->form());

        self::assertResponseRedirects('/stammdaten');

        $client->request('GET', '/stammdaten/90001');

        self::assertResponseStatusCodeSame(404);
    }

    /**
     * Ohne gueltiges Token loescht nichts. Sonst genuegte ein untergeschobenes
     * Formular auf einer fremden Seite, um einen Datensatz zu entfernen.
     */
    public function testDeletingWithoutAValidTokenIsRefused(): void
    {
        $client = self::signedInClient();
        self::givenTwoParties();

        $client->request('POST', '/stammdaten/90001/loeschen', ['_token' => 'falsch']);

        self::assertResponseStatusCodeSame(403);

        $client->request('GET', '/stammdaten/90001');

        self::assertResponseIsSuccessful('Der Datensatz ist noch da');
    }

    /**
     * Die Sperre haengt an der Schnittstelle, an der sich spaeter jedes Modul
     * anmeldet, das auf Stammdaten verweist. Ohne diesen Test waere sie
     * unbelegt — solange es keine Umsetzung gibt, antwortet sie immer
     * "keine Verknuepfungen".
     *
     * Geprueft wird der realistische Fall: die Seite war offen, und erst
     * danach ist die Verknuepfung entstanden. Ein abgeschalteter Knopf haelt
     * dann niemanden mehr auf — die Pruefung im Controller schon.
     */
    public function testARecordThatGotLinkedCannotBeDeletedAnyMore(): void
    {
        $client = self::signedInClient();
        // Ohne das startet der Kernel zwischen den Anfragen neu, und die
        // Attrappe verliert ihren Zustand — die Verknüpfung wäre wieder weg.
        $client->disableReboot();
        self::givenTwoParties();

        $crawler = $client->request('GET', '/stammdaten/90001');
        $deleteForm = $crawler->filter('form[action*="loeschen"]')->form();

        self::linkTo(90001);
        $client->submit($deleteForm);

        self::assertResponseRedirects('/stammdaten/90001');

        $client->request('GET', '/stammdaten/90001');

        self::assertResponseIsSuccessful('Der Datensatz ist noch da');
    }

    public function testTheRecordPageOffersArchivingInsteadOfDeleting(): void
    {
        $client = self::signedInClient();
        self::givenTwoParties();
        self::linkTo(90001);

        $client->request('GET', '/stammdaten/90001');

        self::assertSelectorExists('.ib-action[disabled]', 'Löschen ist abgeschaltet');
        self::assertSelectorNotExists('form[action*="loeschen"]', 'Und es gibt keine Rückfrage dazu');
        self::assertSelectorExists('form[action*="archivieren"]', 'Archivieren steht stattdessen bereit');
    }

    public function testTheOverviewOffersDeletingOnlyWhereItIsPossible(): void
    {
        $client = self::signedInClient();
        self::givenTwoParties();
        self::linkTo(90001);

        $client->request('GET', '/stammdaten');
        $html = (string) $client->getResponse()->getContent();

        self::assertStringContainsString('id="delete-90002"', $html, 'Ohne Verknüpfung mit Rückfrage');
        self::assertStringNotContainsString('id="delete-90001"', $html, 'Mit Verknüpfung ohne Rückfrage');
    }

    public function testArchivingTakesARecordOutOfTheDefaultList(): void
    {
        $client = self::signedInClient();
        self::givenTwoParties();

        $crawler = $client->request('GET', '/stammdaten/90001');
        $client->submit($crawler->filter('form[action*="archivieren"]')->form());

        $client->request('GET', '/stammdaten');

        self::assertStringNotContainsString('Erika Muster', (string) $client->getResponse()->getContent());

        $client->request('GET', '/stammdaten?vergangene=1');

        self::assertStringContainsString('Erika Muster', (string) $client->getResponse()->getContent());
    }

    protected static function testEmail(): string
    {
        return 'party-index-test@example.org';
    }

    private static function linkTo(int $reference): void
    {
        $parties = self::getContainer()->get(PartyRepository::class);
        self::assertInstanceOf(PartyRepository::class, $parties);

        $party = $parties->byReference($reference);
        self::assertInstanceOf(Party::class, $party);

        $stub = self::getContainer()->get(StubPartyLinkSource::class);
        self::assertInstanceOf(StubPartyLinkSource::class, $stub);
        $stub->linkedIds = [$party->id()];
    }

    private static function givenTwoParties(): void
    {
        self::party(90001, 'Muster', 'Erika', [PartyRole::Tenant]);
        self::party(90002, 'Musterbau GmbH', null, [PartyRole::Owner], PartyKind::Company);
    }

    /**
     * @param list<PartyRole> $roles
     */
    private static function party(
        int $reference,
        string $name,
        ?string $givenName,
        array $roles,
        PartyKind $kind = PartyKind::Person,
    ): void {
        $parties = self::getContainer()->get(PartyRepository::class);
        self::assertInstanceOf(PartyRepository::class, $parties);

        $parties->save(new Party(
            $reference,
            $kind,
            $name,
            $givenName,
            PartyRoles::of($roles),
            Addresses::of([PostalAddress::of(AddressKind::Street, 'Musterweg 1', '12345', 'Musterstadt')]),
            ContactDetails::of([Email::fromString('nummer'.$reference.'@example.org')]),
        ));
    }

    private static function removeTestParties(): void
    {
        if (null === self::$kernel) {
            return;
        }

        $entityManager = self::getContainer()->get(EntityManagerInterface::class);

        if (!$entityManager instanceof EntityManagerInterface) {
            return;
        }

        $entityManager->createQuery('DELETE FROM '.Party::class.' p WHERE p.reference >= 90000')->execute();
    }

    /**
     * @param array<string, mixed> $server
     */
    private static function signedInClient(array $server = []): KernelBrowser
    {
        $client = self::signedInWith([PartyPermissions::VIEW, PartyPermissions::EDIT, PartyPermissions::DELETE], $server);
        self::removeTestParties();

        return $client;
    }
}
