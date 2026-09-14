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
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;

/**
 * Der Erfassungsablauf, vor allem sein Anfang.
 *
 * Ein Ablauf haelt seinen Zwischenstand in der Sitzung. Damit stellt sich die
 * Frage, was passiert, wenn jemand ihn abbricht und spaeter wiederkommt — und
 * die Antwort ist fachlich wichtig: "Hinzufuegen" muss einen neuen Datensatz
 * anfangen und nicht die halben Angaben von vorgestern fortsetzen.
 */
final class PartyFlowTest extends WebTestCase
{
    use SignsIn;

    protected function tearDown(): void
    {
        self::removeTestParties();
        self::removeTestUser();

        parent::tearDown();
    }

    public function testAddingStartsOverEvenAfterAnAbandonedDraft(): void
    {
        $client = self::signedInWith([PartyPermissions::VIEW, PartyPermissions::EDIT]);
        self::startADraft($client, '/stammdaten/neu');

        // Weg vom Ablauf und über "Hinzufügen" wieder hinein.
        $client->request('GET', '/stammdaten');
        $crawler = $client->request('GET', '/stammdaten/neu');

        self::assertSelectorTextContains('.ib-flow__step.is-current', 'Art und Rolle', 'Wieder bei Schritt eins');
        self::assertCount(0, $crawler->filter('input[name="roles[]"][checked]'), 'Keine Rolle vorbelegt');
        self::assertSame(
            'person',
            $crawler->filter('input[name="kind"][checked]')->attr('value'),
            'Und wieder die Vorgabe',
        );
    }

    /**
     * Innerhalb des Ablaufs bleibt der Zwischenstand natuerlich erhalten —
     * sonst waere jeder Schritt fuer sich.
     */
    public function testTheDraftSurvivesWithinTheFlow(): void
    {
        $client = self::signedInWith([PartyPermissions::VIEW, PartyPermissions::EDIT]);
        $crawler = self::startADraft($client, '/stammdaten/neu');

        self::assertStringContainsString('step=name', $client->getRequest()->getUri());
        self::assertStringContainsString(
            'Firmenname',
            $crawler->filter('main .ib-field__label')->text(),
            'Die Art aus Schritt eins wirkt nach',
        );
    }

    /**
     * Beim Bearbeiten ist der Anfang der gespeicherte Stand — nicht das, was
     * jemand vor Tagen angefangen und liegen gelassen hat.
     */
    public function testEditingStartsFromWhatIsStored(): void
    {
        $client = self::signedInWith([PartyPermissions::VIEW, PartyPermissions::EDIT]);
        self::givenParty();

        $crawler = $client->request('GET', '/stammdaten/95001/bearbeiten?step=name');
        $client->submit($crawler->selectButton('Weiter')->form(['name' => 'Verworfen']));
        $client->followRedirect();

        $client->request('GET', '/stammdaten');
        $client->request('GET', '/stammdaten/95001/bearbeiten');
        $crawler = $client->request('GET', '/stammdaten/95001/bearbeiten?step=name');

        self::assertSame('Muster', $crawler->filter('#name')->attr('value'));
    }

    /**
     * Eine abgelaufene Sitzung darf nicht dazu fuehren, dass jemand ein leeres
     * Formular fuer einen vorhandenen Datensatz vor sich hat — sonst waere ein
     * Lesezeichen auf einen Schritt ein Weg, die gespeicherten Angaben
     * versehentlich zu ueberschreiben.
     */
    public function testEditingADeepLinkWithoutASessionShowsTheStoredRecord(): void
    {
        $client = self::signedInWith([PartyPermissions::VIEW, PartyPermissions::EDIT]);
        self::givenParty();

        $crawler = $client->request('GET', '/stammdaten/95001/bearbeiten?step=name');

        self::assertSame('Muster', $crawler->filter('#name')->attr('value'));
    }

    /**
     * Aus einer Person wird eine Firma.
     *
     * Der Schritt "Art und Rolle" steht auch beim Bearbeiten offen, und die
     * Pruefung zeigt die neue Art an — dann muss sie auch gespeichert werden.
     * Sie tat es lange nicht: PartyValues::apply() uebernahm alles ausser der
     * Art, und nach dem Abschliessen stand wieder die alte da.
     */
    public function testChangingTheKindWhileEditingIsStored(): void
    {
        $client = self::signedInWith([PartyPermissions::VIEW, PartyPermissions::EDIT]);
        self::givenParty();

        $form = $client->request('GET', '/stammdaten/95001/bearbeiten')->selectButton('Weiter')->form();
        $client->request('POST', $form->getUri(), [
            ...$form->getPhpValues(),
            'kind' => 'company',
            'roles' => ['owner'],
        ]);
        $client->followRedirect();

        $finish = $client->request('GET', '/stammdaten/95001/bearbeiten?step=review')
            ->selectButton('Abschließen')->form();
        $client->submit($finish);

        self::assertResponseRedirects('/stammdaten/95001');
        self::assertSame(PartyKind::Company, self::storedParty()->kind());
    }

    protected static function testEmail(): string
    {
        return 'party-flow-test@example.org';
    }

    /** Schritt eins ausfuellen und weitergehen — der halbe Entwurf. */
    private static function startADraft(KernelBrowser $client, string $url): Crawler
    {
        $form = $client->request('GET', $url)->selectButton('Weiter')->form();

        // Die Werte gehen am Formularobjekt vorbei: ein Kaestchen anzukreuzen
        // hiesse, es ueber seinen Index zu suchen, und der sagt nichts.
        $client->request('POST', $form->getUri(), [
            ...$form->getPhpValues(),
            'kind' => 'company',
            'roles' => ['owner'],
        ]);

        return $client->followRedirect();
    }

    /** Frisch aus der Datenbank, nicht das Objekt aus dem Speicher. */
    private static function storedParty(): Party
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $entityManager->clear();

        $parties = self::getContainer()->get(PartyRepository::class);
        self::assertInstanceOf(PartyRepository::class, $parties);

        $party = $parties->byReference(95001);
        self::assertInstanceOf(Party::class, $party);

        return $party;
    }

    private static function givenParty(): void
    {
        $parties = self::getContainer()->get(PartyRepository::class);
        self::assertInstanceOf(PartyRepository::class, $parties);

        $parties->save(new Party(
            95001,
            PartyKind::Person,
            'Muster',
            'Erika',
            PartyRoles::of([PartyRole::Tenant]),
            Addresses::of([PostalAddress::of(AddressKind::Street, 'Musterweg 1', '12345', 'Musterstadt')]),
            ContactDetails::of([Email::fromString('erika@example.org')]),
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

        $entityManager->createQuery('DELETE FROM '.Party::class.' p WHERE p.reference >= 95000')->execute();
    }
}
