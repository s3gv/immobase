<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Module\Auth\Domain\PersonName;
use App\Module\Auth\Domain\User;
use App\Module\Auth\Domain\UserRepository;
use App\Module\Dashboard\Domain\Reminder;
use App\Module\Dashboard\Domain\ReminderRepository;
use App\Shared\Contact\Email;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Erinnerungen — vorgemerkt, auf dem Zeitstrahl, wieder weg.
 *
 * **Sie gehoeren einem Menschen.** Das ist die Zusage, die zaehlt: was ich
 * mir notiere, sieht niemand sonst, und niemand sonst kann es wegnehmen.
 *
 * Und sie werden streng gelesen: der 30. Februar ist kein Tag. `new
 * DateTimeImmutable()` machte daraus still den 2. Maerz, und wer den Tag
 * nicht wiedererkennt, den er getippt hat, haelt das fuer einen Fehler der
 * Anwendung — zu Recht.
 */
final class ReminderTest extends WebTestCase
{
    use ForgetsRateLimits;
    use SignsIn;

    protected function tearDown(): void
    {
        self::removeTheReminders();
        self::removeStrangers();
        self::removeTestUser();

        parent::tearDown();
    }

    /** Vorgemerkt steht sie in der Klappe und auf dem Zeitstrahl. */
    public function testWhatIsNotedShowsUpOnTheYear(): void
    {
        $client = self::signedInWith([]);

        self::note($client, 'Eigentümerversammlung', 'Objekt 20001', self::thisYear().'-11-12', '18:30');

        $page = $client->request('GET', '/');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Eigentümerversammlung');
        self::assertSelectorExists('.ib-year__mark', 'Eine Markierung auf dem Balken');
    }

    /** Ohne Betreff weiss niemand, woran er erinnert wird. */
    public function testASubjectIsRequired(): void
    {
        $client = self::signedInWith([]);

        self::note($client, '', 'ohne Betreff', self::thisYear().'-11-12', '09:00');

        $client->request('GET', '/');
        self::assertSelectorTextContains('.ib-flash--error', 'Ohne Betreff');
    }

    /**
     * Den 30. Februar gibt es nicht — und er wird auch nicht zum 2. Maerz.
     *
     * Das ist die Falle, gegen die {@see \App\Shared\Time\DateInput} schon
     * einmal gebaut wurde: still umgerechnete Daten fallen niemandem auf.
     */
    public function testTheThirtiethOfFebruaryIsRefused(): void
    {
        $client = self::signedInWith([]);

        self::note($client, 'Unmöglich', '', self::thisYear().'-02-30', '09:00');

        $client->request('GET', '/');
        self::assertSelectorTextContains('.ib-flash--error', 'Diesen Zeitpunkt gibt es nicht');
        self::assertSelectorTextNotContains('body', 'Unmöglich');
    }

    /**
     * Eine fremde Erinnerung laesst sich nicht wegnehmen.
     *
     * Die Abfrage holt ohnehin nur die eigenen — aber die Kennung kommt aus
     * der Adresszeile, und Eingabe wird geprueft und nicht geglaubt.
     *
     * Die fremde Notiz entsteht in der Datenbank und nicht ueber einen
     * zweiten Client: ein Test bootet den Kernel einmal, und zwei Anmeldungen
     * in einem Lauf gibt es nicht. Fuer diese Frage genuegt sie auch — es
     * geht um eine Kennung, die einem anderen gehoert.
     */
    public function testAStrangersReminderStays(): void
    {
        $client = self::signedInWith([]);
        $his = self::aReminderOfSomeoneElse();

        $client->request('POST', '/erinnerungen/'.$his.'/loeschen', [
            '_token' => self::tokenFrom($client),
        ]);

        $client->request('GET', '/');
        self::assertSelectorTextContains('.ib-flash--error', 'Diese Erinnerung gibt es nicht');
        self::assertNotNull(self::reminders()->byId($his), 'Sie steht noch da');
        self::assertSelectorTextNotContains('body', 'Fremde Sache', 'Und sie steht auch nicht auf meinem Zeitstrahl');
    }

    /**
     * Eine Erinnerung wird nicht mit ihrem Termin unloeschbar.
     *
     * Die Klappe ist die einzige Stelle, an der sie sich wegnehmen laesst.
     * Stuende dort nur das Kommende, waere jede vergangene fuer immer
     * stehengeblieben — der Zeitstrahl zeigt sie nur.
     */
    public function testAPastReminderCanStillBeRemoved(): void
    {
        $client = self::signedInWith([]);
        self::note($client, 'Schon gewesen', '', self::thisYear().'-01-05', '09:00');

        $page = $client->request('GET', '/');
        self::assertSelectorTextContains('.ib-remind__panel', 'Schon gewesen', 'Sie steht in der Klappe');

        $remove = $page->filter('.ib-remind__panel form[action$="/loeschen"]')->first();
        $client->request('POST', (string) $remove->attr('action'), [
            '_token' => (string) $remove->filter('input[name="_token"]')->attr('value'),
        ]);

        $client->request('GET', '/');
        self::assertSelectorTextContains('.ib-flash--success', 'Die Erinnerung ist weg.');
        self::assertSelectorTextNotContains('body', 'Schon gewesen');
    }

    /**
     * Was zu nah beieinander liegt, wird eine Markierung.
     *
     * Ein Jahr auf tausend Bildpunkten gibt jedem Tag knapp drei davon: zwei
     * Termine derselben Woche saessen uebereinander, und der eine verdeckte
     * den anderen. Zwei Monate auseinander bleiben es zwei.
     */
    public function testWhatIsCloseTogetherBecomesOneMarker(): void
    {
        $client = self::signedInWith([]);

        self::note($client, 'Am Zwölften', '', self::thisYear().'-11-12', '09:00');
        self::note($client, 'Am Dreizehnten', '', self::thisYear().'-11-13', '09:00');
        self::note($client, 'Im September', '', self::thisYear().'-09-20', '09:00');

        $page = $client->request('GET', '/');

        self::assertCount(2, $page->filter('.ib-year__mark'), 'Zwei Markierungen für drei Termine');
        self::assertSelectorTextContains('.ib-year__pin--many', '2', 'Und eine davon trägt zwei');
    }

    protected static function testEmail(): string
    {
        return 'erinnerung@example.org';
    }

    private static function note(
        KernelBrowser $client,
        string $subject,
        string $note,
        string $day,
        string $time,
    ): void {
        $client->request('POST', '/erinnerungen', [
            '_token' => self::tokenFrom($client),
            'subject' => $subject,
            'note' => $note,
            'day' => $day,
            'time' => $time,
        ]);
    }

    private static function tokenFrom(KernelBrowser $client): string
    {
        $page = $client->request('GET', '/');
        $token = $page->filter('form[action="/erinnerungen"] input[name="_token"]')->attr('value');

        return $token ?? '';
    }

    private static function thisYear(): string
    {
        return date('Y');
    }

    /**
     * Die Notiz eines anderen Kontos — samt dem Konto, an dem sie haengt.
     *
     * Der Fremdschluessel verlangt ein echtes: eine erfundene Kennung liefe
     * in die Einschraenkung und nicht in die Pruefung, um die es geht.
     */
    private static function aReminderOfSomeoneElse(): string
    {
        $users = self::getContainer()->get(UserRepository::class);
        self::assertInstanceOf(UserRepository::class, $users);

        $stranger = new User($users->nextNumber(), Email::fromString('fremder-'.$users->nextNumber().'@example.org'));
        $stranger->nameYourself(PersonName::of('Fremde', 'Person'));
        $stranger->activate();
        $users->save($stranger);

        $reminder = new Reminder($stranger->id(), 'Fremde Sache', '', new DateTimeImmutable(self::thisYear().'-11-12 09:00'));
        self::reminders()->save($reminder);

        return $reminder->id();
    }

    private static function reminders(): ReminderRepository
    {
        $reminders = self::getContainer()->get(ReminderRepository::class);
        self::assertInstanceOf(ReminderRepository::class, $reminders);

        return $reminders;
    }

    /** Das fremde Konto aus {@see aReminderOfSomeoneElse()} raeumt sich nicht selbst weg. */
    private static function removeStrangers(): void
    {
        if (null !== self::$kernel) {
            self::connection()->executeStatement("DELETE FROM auth_user WHERE email LIKE 'fremder-%@example.org'");
        }
    }

    private static function removeTheReminders(): void
    {
        if (null !== self::$kernel) {
            self::connection()->executeStatement('DELETE FROM dashboard_reminder');
        }
    }

    private static function connection(): Connection
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);

        return $entityManager->getConnection();
    }
}
