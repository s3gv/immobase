<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Module\Auth\Contract\AuthenticatedUser;
use App\Module\Auth\Domain\PersonName;
use App\Module\Auth\Domain\User;
use App\Module\Auth\Domain\UserRepository;
use App\Module\Portal\Application\ChangeableRecords;
use App\Module\Portal\Application\Converse;
use App\Module\Portal\Application\DecideAChange;
use App\Module\Portal\Domain\Enquiry;
use App\Module\Portal\Domain\EnquiryRepository;
use App\Module\Portal\Domain\Proposal;
use App\Module\Portal\Domain\ProposalDecision;
use App\Module\Portal\Domain\ProposalRepository;
use App\Module\Portal\Domain\ProposedField;
use App\Module\Property\Domain\Unit;
use App\Module\Property\Domain\UnitRepository;
use App\Shared\Contact\Email;
use App\Shared\Identity\Uuid;
use App\Tests\Module\Portal\Fixture\VanishingRecord;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\DomCrawler\Crawler;

/**
 * Aendern ist ein Vorschlag.
 *
 * Die Zusicherungen, die hier haengen, sind die des ganzen Tickets:
 *
 * * **Nur an Eigenem.** Wem die Einheit nicht gehoert, fuer den gibt es die
 *   Seite nicht — nicht „verboten", sondern nicht vorhanden.
 * * **Nur, was das besitzende Modul freigibt.** Ein Feld, das `fieldsOf()`
 *   nicht nennt, kommt auch ueber ein umgebogenes Formular nicht durch.
 * * **Ablehnen braucht einen Grund**, und entschieden wird **einmal**.
 * * **Was sich zwischenzeitlich geaendert hat, steht da**, bevor es
 *   ueberschrieben wird.
 */
final class PortalChangeTest extends WebTestCase
{
    use BuildsABillableProperty;
    use ForgetsRateLimits;
    use SignsIn;

    private const string MANAGER = 'aenderungspruefer@example.org';

    protected function tearDown(): void
    {
        self::removeEnquiries();
        self::removeManager();
        self::removeTheProperty();
        self::removeTestUser();

        parent::tearDown();
    }

    /** Der Eigentuemer schlaegt vor, der Verwalter uebernimmt, die Einheit stimmt. */
    public function testAnOwnerProposesAndTheManagerAccepts(): void
    {
        $client = self::asTheOwner();
        $unitId = self::aUnitId();

        $form = $client->request('GET', '/portal/aendern/einheit/'.$unitId);
        self::assertResponseIsSuccessful();

        $client->request('POST', '/portal/aendern/einheit/'.$unitId, [
            '_token' => self::tokenOn($form),
            'field_label' => 'WE 1, Gartenwohnung',
            'comment' => 'Der Garten gehört dazu.',
        ]);
        self::assertResponseRedirects();

        $proposal = self::theProposal();
        self::assertSame('WE 1, Gartenwohnung', self::theField($proposal)->wanted());

        self::asTheManager($client);
        $client->request('POST', '/anfragen/'.$proposal->enquiry()->id().'/uebernehmen', [
            '_token' => self::tokenIn($client->request('GET', self::conversationOf($proposal))),
        ]);

        self::assertResponseRedirects();
        self::assertSame('WE 1, Gartenwohnung', self::theUnit($unitId)->label());
        self::assertSame(ProposalDecision::Accepted, self::theProposal()->decision());
    }

    /**
     * Wem sie nicht gehoert, fuer den gibt es die Einheit nicht.
     *
     * Geprueft mit einer Kennung, die es wirklich gibt: eine erfundene waere
     * auch dann nicht zu finden, wenn die Pruefung fehlte.
     */
    public function testWhoeverDoesNotOwnItSeesNothing(): void
    {
        $client = self::createClient();
        self::buildTheProperty();
        self::signInForParty($client, Uuid::v4());

        $client->request('GET', '/portal/aendern/einheit/'.self::aUnitId());

        self::assertResponseStatusCodeSame(404);
    }

    /** Was `fieldsOf()` nicht nennt, kommt nicht durch. */
    public function testASmuggledFieldIsDropped(): void
    {
        $client = self::asTheOwner();
        $unitId = self::aUnitId();

        $form = $client->request('GET', '/portal/aendern/einheit/'.$unitId);
        $client->request('POST', '/portal/aendern/einheit/'.$unitId, [
            '_token' => self::tokenOn($form),
            // Den Miteigentumsanteil gibt `fieldsOf()` nicht frei: er kommt
            // aus der Teilungserklaerung, und an ihm haengt jede Abrechnung.
            'field_mea' => '999',
            'field_note' => 'geschmuggelt',
        ]);

        self::assertResponseRedirects();
        self::assertCount(0, self::enquiries()->forParty(self::anOwner()->id()));
    }

    /** Ohne Begruendung wird nicht abgelehnt. */
    public function testRejectingNeedsAReason(): void
    {
        $client = self::asTheOwner();
        $proposal = self::aProposalFrom($client);

        self::asTheManager($client);
        $client->request('POST', '/anfragen/'.$proposal->enquiry()->id().'/ablehnen', [
            '_token' => self::tokenIn($client->request('GET', self::conversationOf($proposal))),
            'reason' => '   ',
        ]);

        self::assertResponseRedirects();
        self::assertTrue(self::theProposal()->isOpen());
    }

    /**
     * Entschieden wird einmal.
     *
     * Ein zweites Uebernehmen schriebe den Stand von damals ueber den von
     * heute — und niemand haette darum gebeten.
     */
    public function testAProposalIsDecidedOnce(): void
    {
        $client = self::asTheOwner();
        $proposal = self::aProposalFrom($client);

        self::asTheManager($client);
        $token = self::tokenIn($client->request('GET', self::conversationOf($proposal)));

        $client->request('POST', '/anfragen/'.$proposal->enquiry()->id().'/uebernehmen', ['_token' => $token]);
        $client->request('POST', '/anfragen/'.$proposal->enquiry()->id().'/ablehnen', [
            '_token' => $token,
            'reason' => 'Doch nicht.',
        ]);

        self::assertSame(ProposalDecision::Accepted, self::theProposal()->decision());
    }

    /**
     * Was das besitzende Modul nicht annimmt, wird nicht uebernommen.
     *
     * Die Regeln fuer eine Bezeichnung kennt das Objektmodul, nicht das
     * Portal. Sein Einwand steht als Meldung da — und die Einheit heisst
     * weiter, wie sie hiess.
     */
    public function testAnUnacceptableProposalChangesNothing(): void
    {
        $client = self::asTheOwner();
        $unitId = self::aUnitId();
        $form = $client->request('GET', '/portal/aendern/einheit/'.$unitId);

        $client->request('POST', '/portal/aendern/einheit/'.$unitId, [
            '_token' => self::tokenOn($form),
            'field_label' => '   ',
        ]);

        $proposal = self::theProposal();
        self::asTheManager($client);
        $client->request('POST', '/anfragen/'.$proposal->enquiry()->id().'/uebernehmen', [
            '_token' => self::tokenIn($client->request('GET', self::conversationOf($proposal))),
        ]);

        self::assertSame('WE 1', self::theUnit($unitId)->label());
        self::assertTrue(self::theProposal()->isOpen());
    }

    /**
     * Zwei Entscheidungen gehen nicht beide durch.
     *
     * Der Uebergang von „offen" liegt in der Datenbank: wer ihn nicht mehr
     * offen vorfindet, bekommt ihn nicht — und schreibt dann auch nichts,
     * weder die Aenderung noch eine zweite Bestaetigung ins Gespraech.
     */
    public function testOnlyOneDecisionGetsThrough(): void
    {
        $client = self::asTheOwner();
        $proposal = self::aProposalFrom($client);
        self::asTheManager($client);

        $first = self::decisions()->accept($proposal, self::theManager(), 'Übernommen.');
        $second = self::decisions()->accept(self::theProposal(), self::theManager(), 'Noch einmal.');

        self::assertSame([], $first);
        self::assertSame(['' => 'change.error.decided'], $second);
        // Eine Frage, eine Bestaetigung — und keine zweite.
        self::assertCount(2, self::theProposal()->enquiry()->messages());
    }

    /**
     * Verschwindet der Datensatz beim Uebernehmen, faellt alles zurueck.
     *
     * `apply()` kehrt in dem Fall still zurueck. Ohne die Nachschau stuende
     * der Vorschlag danach als uebernommen da, ohne dass sich etwas geaendert
     * haette — und im Gespraech stuende eine Bestaetigung, die nicht stimmt.
     */
    public function testAVanishedRecordRollsEverythingBack(): void
    {
        $client = self::asTheOwner();
        $proposal = self::aProposalFrom($client);
        self::asTheManager($client);

        $decisions = new DecideAChange(
            new ChangeableRecords([new VanishingRecord()]),
            self::fromContainer(Converse::class),
            self::fromContainer(ProposalRepository::class),
            self::fromContainer(ClockInterface::class),
        );

        $objections = $decisions->accept($proposal, self::theManager(), 'Übernommen.');

        self::assertSame(['' => 'change.error.not_applied'], $objections);
        self::assertTrue(self::theProposal()->isOpen(), 'Der Vorschlag steht nicht mehr offen.');
        self::assertCount(1, self::theProposal()->enquiry()->messages());
    }

    /** Was sich zwischenzeitlich geaendert hat, steht da — vor dem Uebernehmen. */
    public function testAChangeInTheMeantimeIsShown(): void
    {
        $client = self::asTheOwner();
        $proposal = self::aProposalFrom($client);

        // Jemand im Verwalterbereich fasst dasselbe Feld an.
        $unit = self::theUnit(self::aUnitId());
        $unit->describe('WE 1, inzwischen anders', $unit->usage());
        self::units()->save($unit);

        $decisions = self::getContainer()->get(DecideAChange::class);
        self::assertInstanceOf(DecideAChange::class, $decisions);

        self::assertSame(
            ['label' => 'WE 1, inzwischen anders'],
            $decisions->changedMeanwhile(self::theProposal()),
        );
    }

    protected static function testEmail(): string
    {
        return 'aenderungsvorschlag@example.org';
    }

    private static function asTheOwner(): KernelBrowser
    {
        $client = self::createClient();
        self::buildTheProperty();
        self::signInForParty($client, self::anOwner()->id());

        return $client;
    }

    /**
     * Vom Portalkonto zum Verwalterkonto — an demselben Client.
     *
     * Der Kernel faehrt nur einmal hoch, und der Vorgang hat zwei Seiten: der
     * eine schlaegt vor, der andere entscheidet. Ein zweiter Client waere
     * nicht moeglich, ein zweiter Testlauf waere die halbe Zusicherung.
     */
    private static function asTheManager(KernelBrowser $client): void
    {
        self::removeManager();

        $manager = new User(self::users()->nextNumber(), Email::fromString(self::MANAGER));
        $manager->nameYourself(PersonName::of('Prüferin', 'Petra'));
        $manager->changePassword('$2y$13$ojDeCXcOJLQF4YQfBBRcuOqBu4jRDlNQCEnbHTIRJRQ6MgxdvwGmi');
        $manager->activate();
        $manager->assignRoles([self::roleFor(null)]);

        self::users()->save($manager);
        $client->loginUser($manager);
    }

    /** Ein Vorschlag auf dem Weg, den auch ein Mensch nimmt. */
    private static function aProposalFrom(KernelBrowser $client): Proposal
    {
        $unitId = self::aUnitId();
        $form = $client->request('GET', '/portal/aendern/einheit/'.$unitId);

        $client->request('POST', '/portal/aendern/einheit/'.$unitId, [
            '_token' => self::tokenOn($form),
            'field_label' => 'WE 1, Gartenwohnung',
        ]);

        return self::theProposal();
    }

    private static function theManager(): AuthenticatedUser
    {
        $manager = self::users()->findByEmail(Email::fromString(self::MANAGER));
        self::assertInstanceOf(User::class, $manager);

        return new AuthenticatedUser(
            id: $manager->id(),
            number: $manager->number(),
            email: self::MANAGER,
            displayName: $manager->displayName(),
            jobTitle: '',
            isActive: true,
            isAdministrator: true,
        );
    }

    private static function decisions(): DecideAChange
    {
        return self::fromContainer(DecideAChange::class);
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $service
     *
     * @return T
     */
    private static function fromContainer(string $service): object
    {
        $found = self::getContainer()->get($service);
        self::assertInstanceOf($service, $found);

        return $found;
    }

    /** Die erste Einheit des Objekts — es gibt sie, die Zusicherung steht hier. */
    private static function aUnitId(): string
    {
        $id = self::unitIds()[0] ?? null;
        self::assertIsString($id, 'Das Objekt hat keine Einheit.');

        return $id;
    }

    private static function theField(Proposal $proposal): ProposedField
    {
        $field = $proposal->fields()[0] ?? null;
        self::assertInstanceOf(ProposedField::class, $field);

        return $field;
    }

    private static function theProposal(): Proposal
    {
        $enquiries = self::enquiries()->forParty(self::anOwner()->id());
        self::assertCount(1, $enquiries, 'Es gibt nicht genau eine Anfrage mit Vorschlag.');
        $proposal = $enquiries[0]->proposal();
        self::assertInstanceOf(Proposal::class, $proposal);

        return $proposal;
    }

    private static function conversationOf(Proposal $proposal): string
    {
        return '/anfragen/'.$proposal->enquiry()->id().'/bearbeiten/gespraech';
    }

    private static function theUnit(string $id): Unit
    {
        $unit = self::units()->byId($id);
        self::assertInstanceOf(Unit::class, $unit);

        return $unit;
    }

    private static function units(): UnitRepository
    {
        $units = self::getContainer()->get(UnitRepository::class);
        self::assertInstanceOf(UnitRepository::class, $units);

        return $units;
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
        $token = $crawler->filter('input[name="_token"]')->first();

        return 0 === $token->count() ? '' : (string) $token->attr('value');
    }

    /** Das Token des Antwortformulars — auf der Seite stehen mehrere. */
    private static function tokenIn(Crawler $crawler): string
    {
        $token = $crawler->filter('form[action$="/antwort"] input[name="_token"]')->first();

        return 0 === $token->count() ? '' : (string) $token->attr('value');
    }

    private static function removeManager(): void
    {
        if (null === self::$kernel) {
            return;
        }

        $entityManager = self::getContainer()->get(EntityManagerInterface::class);

        if (!$entityManager instanceof EntityManagerInterface) {
            return;
        }

        $entityManager->createQuery('DELETE FROM '.User::class.' u WHERE u.email = :email')
            ->setParameter('email', self::MANAGER)
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
