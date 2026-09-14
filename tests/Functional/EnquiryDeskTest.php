<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Module\Auth\Contract\AuthenticatedUser;
use App\Module\Auth\Domain\PersonName;
use App\Module\Auth\Domain\User;
use App\Module\Auth\Domain\UserRepository;
use App\Module\Portal\Application\Converse;
use App\Module\Portal\Application\EnquiriesOfAParty;
use App\Module\Portal\Application\SweepThePortal;
use App\Module\Portal\Domain\Enquiry;
use App\Module\Portal\Domain\EnquiryRepository;
use App\Module\Portal\Domain\EnquiryState;
use App\Module\Portal\Domain\Message;
use App\Module\Portal\Domain\PortalPermissions;
use App\Shared\Contact\Email;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Twig\Environment;

/**
 * Anfragen aus der Sicht der Verwaltung.
 *
 * Drei Zusicherungen, und die dritte ist die, an der eine Anwendung zum
 * Spam-Werkzeug wird:
 *
 * * **Wer antwortet, wird zugewiesen**, wenn es noch niemand ist.
 * * **Ohne das Recht gibt es die Seiten nicht.**
 * * **Hoechstens eine Mail, bis gelesen wurde** — fuenf Nachrichten
 *   hintereinander sind eine Mail, und nach ihr kommt keine zweite, solange
 *   der Empfaenger nicht gelesen hat.
 */
final class EnquiryDeskTest extends WebTestCase
{
    use BuildsABillableProperty;
    use ForgetsRateLimits;
    use SignsIn;

    private const string PORTAL_EMAIL = 'anfragenportal@example.org';

    protected function tearDown(): void
    {
        self::removeEnquiries();
        self::removePortalAccount();
        self::removeTheProperty();
        self::removeTestUser();

        parent::tearDown();
    }

    /** Die Uebersicht zaehlt, was offen ist und was noch niemand gelesen hat. */
    public function testTheDeskShowsWhatIsWaiting(): void
    {
        $client = self::asStaff([PortalPermissions::VIEW]);
        self::anEnquiry('Heizung tropft');

        $page = $client->request('GET', '/anfragen');

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Heizung tropft', $page->text());
        self::assertStringContainsString('Paula Prüfer', $page->text());
        // Die Kachel „Ungelesen" und das Abzeichen am Menuepunkt zaehlen
        // dasselbe: was noch niemand angesehen hat.
        self::assertStringContainsString('ungelesene Anfragen', $page->html());
    }

    /** Oeffnen heisst gelesen — danach zaehlt das Abzeichen sie nicht mehr. */
    public function testOpeningCountsAsReading(): void
    {
        $client = self::asStaff([PortalPermissions::VIEW]);
        $enquiry = self::anEnquiry('Gelesen wird beim Öffnen');

        $client->request('GET', '/anfragen/'.$enquiry->id().'/bearbeiten/gespraech');
        self::assertResponseIsSuccessful();

        self::assertFalse(self::reload($enquiry)->isUnreadByStaff());
    }

    /**
     * Wer eine nicht zugewiesene Anfrage beantwortet, wird dabei zugewiesen.
     *
     * Ohne das stuende am Monatsende die Haelfte ohne Bearbeiter da, obwohl
     * jede bearbeitet wurde.
     */
    public function testAnsweringAssignsTheAnswerer(): void
    {
        $client = self::asStaff([PortalPermissions::VIEW, PortalPermissions::EDIT]);
        $enquiry = self::anEnquiry('Wer kümmert sich?');

        $page = $client->request('GET', '/anfragen/'.$enquiry->id().'/bearbeiten/gespraech');
        $client->request('POST', '/anfragen/'.$enquiry->id().'/antwort', [
            '_token' => self::tokenOn($page),
            'body' => 'Wir schauen morgen vorbei.',
        ]);

        self::assertResponseRedirects();
        $answered = self::reload($enquiry);

        self::assertSame(EnquiryState::Answered, $answered->state());
        self::assertNotNull($answered->assigneeUserId());
        self::assertNotNull($answered->notifyDueAt());
    }

    /**
     * Zwei Antworten kurz hintereinander verschieben den Termin nicht.
     *
     * Sonst schoebe jede weitere Nachricht die Mail nach hinten, und bei
     * fortlaufendem Schreiben kaeme nie eine.
     */
    public function testASecondAnswerDoesNotPostponeTheNotification(): void
    {
        self::asStaff([PortalPermissions::VIEW, PortalPermissions::EDIT]);
        $enquiry = self::anEnquiry('Zweimal kurz hintereinander');

        self::converse()->answer($enquiry, self::someoneFromTheOffice(), 'Erste Antwort.');
        $first = $enquiry->notifyDueAt();

        self::converse()->answer($enquiry, self::someoneFromTheOffice(), 'Zweite Antwort.');

        self::assertNotNull($first);
        self::assertEquals($first, $enquiry->notifyDueAt());
    }

    /**
     * Der Lauf benachrichtigt genau einmal — und erst nach dem Lesen wieder.
     *
     * Die Regel gegen das Spam-Werkzeug, in einem Stueck: faellig, verschickt,
     * nichts mehr; gelesen, faellig, wieder eine.
     */
    public function testAtMostOneNotificationUntilItIsRead(): void
    {
        self::asStaff([PortalPermissions::VIEW]);
        $enquiry = self::anAnsweredEnquiry();

        $enquiry->notifyAt(new DateTimeImmutable('-1 minute'));
        self::enquiries()->save($enquiry);
        self::assertSame(1, self::sweep()['notified']);

        // Noch einmal faellig stellen — ausdruecklich gespeichert, sonst
        // pruefte der Test nur den Arbeitsspeicher: der Vermerk haelt die
        // zweite Mail auf.
        $again = self::reload($enquiry);
        $again->notifyAt(new DateTimeImmutable('-1 minute'));
        self::enquiries()->save($again);
        self::assertSame(0, self::sweep()['notified']);

        // Gelesen — und danach schreibt die Verwaltung erneut. Das ist der
        // neue Zyklus: ohne eine neue Nachricht gaebe es nichts zu melden.
        $read = self::reload($enquiry);
        $read->readByParty(new DateTimeImmutable('-30 minutes'));
        new Message(
            $read,
            self::someoneFromTheOffice()->id,
            'Katrin · Buchhaltung',
            'Und noch etwas.',
            new DateTimeImmutable('-20 minutes'),
        );
        $read->notifyAt(new DateTimeImmutable('-1 minute'));
        self::enquiries()->save($read);

        self::assertSame(1, self::sweep()['notified']);
    }

    /**
     * In der Mail steht kein Inhalt.
     *
     * Eine Mail ist unverschluesselte Post. Der Betreff steht drin, weil drei
     * solcher Mails sonst ununterscheidbar waeren — der Text der Nachricht
     * nicht.
     */
    public function testTheMailCarriesNoContent(): void
    {
        self::asStaff([PortalPermissions::VIEW]);
        $twig = self::getContainer()->get(Environment::class);
        self::assertInstanceOf(Environment::class, $twig);

        $context = [
            'subject' => 'Heizung tropft',
            'link' => 'https://example.org/portal/anfragen/1',
            'body' => 'IM KELLER STEHT WASSER',
        ];

        foreach (['email/portal_message.txt.twig', 'email/portal_message.html.twig'] as $template) {
            $rendered = $twig->render($template, $context);

            self::assertStringContainsString('Heizung tropft', $rendered);
            self::assertStringContainsString('example.org/portal/anfragen/1', $rendered);
            self::assertStringNotContainsString('IM KELLER STEHT WASSER', $rendered);
        }
    }

    /** Ohne das Recht gibt es die Seiten nicht — auch die Adresse nicht. */
    public function testWithoutTheRightThereIsNoDesk(): void
    {
        $client = self::asStaff(['parties.view']);
        $enquiry = self::anEnquiry('Nichts für Fremde');

        foreach ([
            '/anfragen',
            '/anfragen/'.$enquiry->id().'/bearbeiten/gespraech',
            '/anfragen/anhang/'.$enquiry->id(),
        ] as $path) {
            $client->request('GET', $path);
            self::assertResponseStatusCodeSame(403, $path.' steht ohne das Recht offen.');
        }
    }

    /** Ansehen darf mehr: ohne „bearbeiten" geht keine Antwort hinaus. */
    public function testReadingIsNotAnswering(): void
    {
        $client = self::asStaff([PortalPermissions::VIEW]);
        $enquiry = self::anEnquiry('Nur lesen');

        $client->request('POST', '/anfragen/'.$enquiry->id().'/antwort', ['body' => 'Geht nicht.']);

        self::assertResponseStatusCodeSame(403);
        self::assertSame(EnquiryState::Open, self::reload($enquiry)->state());
    }

    /**
     * Was mit dem Stammdatensatz verschwindet — und dass es vorher dasteht.
     *
     * Ein laufendes Gespraech stillschweigend wegzuraeumen, auf dessen
     * Antwort jemand wartet, ist genau die Sorte Ueberraschung, die man einer
     * Verwaltung nicht zumutet. Und ein Konto, das fuer niemanden mehr
     * spricht, darf sich nicht anmelden koennen.
     */
    public function testEnquiriesBelongToTheContactAndGoWithIt(): void
    {
        self::asStaff([PortalPermissions::VIEW]);
        self::anEnquiry('Geht mit');
        $partyId = self::anOwner()->id();

        $belongings = self::getContainer()->get(EnquiriesOfAParty::class);
        self::assertInstanceOf(EnquiriesOfAParty::class, $belongings);

        $announced = implode(' ', $belongings->announceFor([$partyId])[$partyId] ?? []);
        self::assertStringContainsString('Nachrichten und Anhängen', $announced);
        self::assertStringContainsString('Portalzugang', $announced);

        $belongings->discardFor([$partyId]);

        self::assertCount(0, self::enquiries()->forParty($partyId));
        self::assertNull(self::users()->forParty($partyId));
    }

    protected static function testEmail(): string
    {
        return 'anfragenschalter@example.org';
    }

    /**
     * @param list<string> $permissions
     */
    private static function asStaff(array $permissions): KernelBrowser
    {
        $client = self::signedInWith($permissions);
        self::buildTheProperty();
        self::aPortalAccountFor(self::anOwner()->id());

        return $client;
    }

    /** Eine Anfrage aus dem Portal — offen und von niemandem gelesen. */
    private static function anEnquiry(string $subject, string $when = '-2 hours'): Enquiry
    {
        $asked = new DateTimeImmutable($when);
        $enquiry = new Enquiry(self::enquiries()->nextNumber(), self::anOwner()->id(), $subject, $asked);
        new Message($enquiry, null, 'Paula Prüfer', 'Bitte einmal ansehen.', $asked);
        self::enquiries()->save($enquiry);

        return $enquiry;
    }

    /**
     * Dieselbe Anfrage, aber schon beantwortet — sonst waere nichts faellig.
     *
     * Frage und Antwort liegen ausdruecklich Stunden auseinander. Die Spalte
     * speichert Sekunden ohne Bruchteile; zwei Nachrichten im selben
     * Augenblick waeren nach dem Laden gleich alt, und „seither etwas Neues"
     * liesse sich daran nicht mehr ablesen.
     */
    private static function anAnsweredEnquiry(): Enquiry
    {
        $enquiry = self::anEnquiry('Schon beantwortet');
        new Message(
            $enquiry,
            self::someoneFromTheOffice()->id,
            'Katrin · Buchhaltung',
            'Danke!',
            new DateTimeImmutable('-1 hour'),
        );
        self::enquiries()->save($enquiry);

        return $enquiry;
    }

    private static function someoneFromTheOffice(): AuthenticatedUser
    {
        return new AuthenticatedUser(
            id: self::staffId(),
            number: 1,
            email: self::testEmail(),
            displayName: 'Test Konto',
            jobTitle: 'Objektbetreuung',
            isActive: true,
            isAdministrator: false,
        );
    }

    private static function staffId(): string
    {
        $user = self::users()->findByEmail(Email::fromString(self::testEmail()));
        self::assertInstanceOf(User::class, $user);

        return $user->id();
    }

    /**
     * Ein Portalkonto fuer die Partei — sonst gibt es niemanden zu
     * benachrichtigen.
     */
    private static function aPortalAccountFor(string $partyId): void
    {
        self::removePortalAccount();

        $account = new User(self::users()->nextNumber(), Email::fromString(self::PORTAL_EMAIL), $partyId);
        $account->nameYourself(PersonName::of('Paula', 'Prüfer'));
        $account->changePassword('$2y$13$ojDeCXcOJLQF4YQfBBRcuOqBu4jRDlNQCEnbHTIRJRQ6MgxdvwGmi');
        $account->activate();

        self::users()->save($account);
    }

    /**
     * @return array{forgotten: int, notified: int}
     */
    private static function sweep(): array
    {
        $sweep = self::getContainer()->get(SweepThePortal::class);
        self::assertInstanceOf(SweepThePortal::class, $sweep);

        return $sweep();
    }

    private static function reload(Enquiry $enquiry): Enquiry
    {
        $found = self::enquiries()->byId($enquiry->id());
        self::assertInstanceOf(Enquiry::class, $found);

        return $found;
    }

    private static function converse(): Converse
    {
        $converse = self::getContainer()->get(Converse::class);
        self::assertInstanceOf(Converse::class, $converse);

        return $converse;
    }

    private static function enquiries(): EnquiryRepository
    {
        $enquiries = self::getContainer()->get(EnquiryRepository::class);
        self::assertInstanceOf(EnquiryRepository::class, $enquiries);

        return $enquiries;
    }

    private static function users(): UserRepository
    {
        $users = self::getContainer()->get(UserRepository::class);
        self::assertInstanceOf(UserRepository::class, $users);

        return $users;
    }

    private static function tokenOn(Crawler $crawler): string
    {
        $token = $crawler->filter('main input[name="_token"]')->first();

        return 0 === $token->count() ? '' : (string) $token->attr('value');
    }

    private static function removePortalAccount(): void
    {
        if (null === self::$kernel) {
            return;
        }

        $entityManager = self::getContainer()->get(EntityManagerInterface::class);

        if (!$entityManager instanceof EntityManagerInterface) {
            return;
        }

        $entityManager->createQuery('DELETE FROM '.User::class.' u WHERE u.email = :email')
            ->setParameter('email', self::PORTAL_EMAIL)
            ->execute();
    }

    private static function removeEnquiries(): void
    {
        if (null === self::$kernel) {
            return;
        }

        $entityManager = self::getContainer()->get(EntityManagerInterface::class);

        if (!$entityManager instanceof EntityManagerInterface) {
            return;
        }

        foreach ($entityManager->getRepository(Enquiry::class)->findAll() as $enquiry) {
            $entityManager->remove($enquiry);
        }

        $entityManager->flush();
    }
}
