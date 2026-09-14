<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Module\Audit\Application\SweepTheTrail;
use App\Module\Audit\Domain\AuditFilter;
use App\Module\Audit\Domain\AuditPermissions;
use App\Module\Audit\Domain\AuditRepository;
use App\Module\Auth\Domain\Rbac\GrantedPermissions;
use App\Module\Auth\Domain\Rbac\PermissionAssignments;
use App\Module\Auth\Domain\User;
use App\Module\Auth\Domain\UserRepository;
use App\Module\Party\Domain\PartyPermissions;
use App\Shared\Audit\AuditAction;
use App\Shared\Contact\Email;
use App\Shared\Ui\Page;
use App\Tests\Module\Audit\Fixture\ProtocolThatCanFail;
use Doctrine\ORM\EntityManagerInterface;
use RuntimeException;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Das Aenderungsprotokoll.
 *
 * **Es haengt an Doctrine und nicht an Fachereignissen.** Deshalb gibt es
 * keinen Schreibweg, der daran vorbeikommt — und deshalb wird hier nicht
 * geprueft, ob ein bestimmtes Modul protokolliert, sondern ob ueberhaupt
 * etwas geschrieben werden kann, ohne eine Spur zu hinterlassen.
 */
final class AuditTrailTest extends WebTestCase
{
    use BuildsABillableProperty;
    use ForgetsRateLimits;
    use SignsIn;

    protected function tearDown(): void
    {
        self::removeTheProperty();
        self::removeTestUser();
        self::forgetTheTrail();

        parent::tearDown();
    }

    /**
     * Ein Schreibvorgang steht danach im Protokoll — mit Name und Datensatz.
     *
     * Das Bauwerk der Tests legt ein Objekt mit Einheiten und Kontakten an;
     * nichts davon weiss vom Protokoll, und genau das ist die Zusicherung.
     */
    public function testEveryWriteLeavesATrace(): void
    {
        self::signedInWith([PartyPermissions::VIEW]);
        self::forgetTheTrail();
        self::buildTheProperty();

        $written = self::entries(AuditFilter::of(AuditAction::Created->value, 'Prüfweg'));

        self::assertNotSame([], $written, 'Das angelegte Objekt steht im Protokoll');
        self::assertSame('Property', $written[0]->record());
        self::assertStringContainsString('Test Konto', $written[0]->actor(), 'Und wer es angelegt hat');

        // Und es protokolliert nicht sich selbst: sonst zoege jeder Eintrag
        // beim naechsten Speichern den naechsten nach sich, und das
        // Protokoll waere nach einem Tag voll von sich.
        self::assertSame(
            [],
            self::entries(AuditFilter::of(null, 'AuditEntry')),
            'Das Protokoll steht nicht in sich selbst',
        );
    }

    /**
     * Eine Rechtevergabe steht im Protokoll — obwohl sie an der ORM vorbeigeht.
     *
     * Die Zuordnungstabellen halten Zeichenketten und keine Entitaeten; ein
     * `DELETE` ueber DBAL loest keinen Lebenszyklus aus. Waere dieser Weg
     * nicht eigens gemeldet, ginge ausgerechnet der Vorgang spurlos durch,
     * bei dem am ehesten jemand wissen will, wer ihn ausgeloest hat.
     */
    public function testGrantingPermissionsIsRecorded(): void
    {
        self::signedInWith([AuditPermissions::VIEW]);
        self::forgetTheTrail();

        $assignments = self::getContainer()->get(PermissionAssignments::class);
        self::assertInstanceOf(PermissionAssignments::class, $assignments);

        $assignments->setForUser(self::someId(), GrantedPermissions::of([PartyPermissions::VIEW]));

        $granted = self::entries(AuditFilter::of(null, 'parties.view'));
        self::assertNotSame([], $granted, 'Die Vergabe steht da');
        self::assertSame('User', $granted[0]->record());
        self::assertSame(AuditAction::PermissionsChanged, $granted[0]->action());

        // Und der Entzug ebenso: „keine Rechte" ist kein „nichts passiert".
        $assignments->setForUser(self::someId(), GrantedPermissions::none());

        $withdrawn = self::entries(AuditFilter::of(AuditAction::PermissionsChanged->value, 'entzogen'));
        self::assertNotSame([], $withdrawn, 'Der Entzug auch');

        // Und er heisst nicht „geloescht": das Konto gibt es noch.
        self::assertSame([], self::entries(AuditFilter::of(AuditAction::Deleted->value, null)));
    }

    /**
     * Scheitert die Protokollzeile, gilt auch die Rechtevergabe nicht.
     *
     * Waere die Zeile nach der Vergabe geschrieben, bliebe hier eine wirksame
     * Vergabe ohne Spur zurueck — bei diesem Vorgang der eine Ausgang, den es
     * nicht geben darf. Ein Protokoll, das auf Kommando scheitert, ist die
     * einzige Art, das zu fragen: gelingt beides, sieht man keinen
     * Unterschied.
     */
    public function testAFailedTraceTakesTheGrantWithIt(): void
    {
        self::signedInWith([AuditPermissions::VIEW]);

        $assignments = self::getContainer()->get(PermissionAssignments::class);
        self::assertInstanceOf(PermissionAssignments::class, $assignments);

        $assignments->setForUser(self::someId(), GrantedPermissions::of([PartyPermissions::VIEW]));

        $protocol = self::getContainer()->get(ProtocolThatCanFail::class);
        self::assertInstanceOf(ProtocolThatCanFail::class, $protocol);
        $protocol->failNext();

        try {
            $assignments->setForUser(self::someId(), GrantedPermissions::of([PartyPermissions::EDIT]));
            self::fail('Das Protokoll hat nicht gemeldet, dass es scheitert');
        } catch (RuntimeException) {
            // So soll es sein.
        }

        self::assertSame(
            [PartyPermissions::VIEW],
            $assignments->ofUser(self::someId())->toList(),
            'Die zweite Vergabe ist mit ihrer Zeile zurückgenommen',
        );
    }

    /** Eine Anmeldung auch — das ist der Grund, warum es Anmeldungen gibt. */
    public function testSigningInIsRecorded(): void
    {
        $client = self::signedInWith([AuditPermissions::VIEW]);
        $client->request('GET', '/protokoll');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Protokoll');
    }

    /** Ohne das Recht kommt niemand an die Liste. */
    public function testTheTrailNeedsItsOwnRight(): void
    {
        $client = self::signedInWith([PartyPermissions::VIEW]);

        $client->request('GET', '/protokoll');
        self::assertResponseStatusCodeSame(403);

        $client->request('GET', '/protokoll/pdf');
        self::assertResponseStatusCodeSame(403, 'Der Abzug ist dieselbe Auskunft');
    }

    /** Der Abzug ist ein PDF und traegt, was dasteht. */
    public function testTheSheetCarriesTheTrail(): void
    {
        $client = self::signedInWith([AuditPermissions::VIEW]);
        self::buildTheProperty();

        $client->request('GET', '/protokoll/pdf');

        self::assertResponseIsSuccessful();
        self::assertSame('application/pdf', $client->getResponse()->headers->get('Content-Type'));
        self::assertStringStartsWith('%PDF', (string) $client->getResponse()->getContent());
    }

    /**
     * Nach achtundvierzig Stunden ist eine Zeile weg.
     *
     * Ein Protokoll ohne Frist waechst still, bis es die Datenbank fuellt —
     * und niemand entscheidet je, wann es genug ist.
     */
    public function testWhatIsOlderThanTwoDaysIsForgotten(): void
    {
        self::signedInWith([AuditPermissions::VIEW]);
        self::forgetTheTrail();
        self::buildTheProperty();

        $before = self::entries(AuditFilter::none());
        self::assertNotSame([], $before);

        self::ageTheTrail();

        $sweep = self::getContainer()->get(SweepTheTrail::class);
        self::assertInstanceOf(SweepTheTrail::class, $sweep);
        self::assertGreaterThan(0, $sweep(), 'Der Aufräumer nimmt etwas mit');

        self::assertSame([], self::entries(AuditFilter::none()), 'Und danach ist nichts mehr da');
    }

    protected static function testEmail(): string
    {
        return 'protokoll@example.org';
    }

    /**
     * Die Kennung des angemeldeten Kontos.
     *
     * Ein echtes und kein ausgedachtes: die Zuordnungstabelle haelt einen
     * Fremdschluessel, und eine erfundene Kennung liefe in ihn statt in die
     * Pruefung, um die es geht.
     */
    private static function someId(): string
    {
        $users = self::getContainer()->get(UserRepository::class);
        self::assertInstanceOf(UserRepository::class, $users);

        $user = $users->findByEmail(Email::fromString(self::testEmail()));
        self::assertInstanceOf(User::class, $user);

        return $user->id();
    }

    /**
     * @return list<\App\Module\Audit\Domain\AuditEntry>
     */
    private static function entries(AuditFilter $filter): array
    {
        $entries = self::getContainer()->get(AuditRepository::class);
        self::assertInstanceOf(AuditRepository::class, $entries);

        return $entries->matching($filter, Page::of(1, 500));
    }

    /** Alles um drei Tage zurueckdatieren — schneller als drei Tage warten. */
    private static function ageTheTrail(): void
    {
        self::connection()->executeStatement("UPDATE audit_entry SET at = at - interval '3 days'");
    }

    private static function forgetTheTrail(): void
    {
        if (null !== self::$kernel) {
            self::connection()->executeStatement('DELETE FROM audit_entry');
        }
    }

    private static function connection(): \Doctrine\DBAL\Connection
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);

        return $entityManager->getConnection();
    }
}
