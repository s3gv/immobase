<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Module\Auth\Application\SetUpAccount;
use App\Module\Auth\Domain\PersonName;
use App\Module\Auth\Domain\SecondFactor;
use App\Module\Auth\Domain\SecondFactorSettings;
use App\Module\Auth\Domain\SignInToken;
use App\Module\Auth\Domain\TokenHasher;
use App\Module\Auth\Domain\TokenPurpose;
use App\Module\Auth\Domain\TokenRepository;
use App\Module\Auth\Domain\User;
use App\Module\Auth\Domain\UserRepository;
use App\Shared\Contact\Email;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Die Wege, auf denen jemand ein fremdes Konto uebernehmen koennte.
 *
 * Ein Link aus dem Postfach, eine offene Sitzung an einem fremden Bildschirm,
 * ein Konto, das laengst deaktiviert ist: keiner davon darf den zweiten
 * Faktor ersetzen, ein zweites Mal ein Passwort setzen oder weiterarbeiten.
 */
final class AccountTakeoverTest extends WebTestCase
{
    use ForgetsRateLimits;
    use SignsIn;

    private const string EMAIL = 'uebernahme@example.org';
    private const string PASSWORD = 'die katze schlaeft auf dem sofa';
    private const string NEW_PASSWORD = 'ein ganz anderes langes passwort';
    private const string SECRET = 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ';

    protected function setUp(): void
    {
        self::bootKernel();
        self::forgetRateLimits();
        self::ensureKernelShutdown();
    }

    protected function tearDown(): void
    {
        self::forgetRateLimits();
        self::removeUser();
        self::removeTestUser();

        parent::tearDown();
    }

    /** Wer an das Postfach kommt, haengt damit nicht seine eigene App an das Konto. */
    public function testAResetKeepsTheSecondFactorThatIsThere(): void
    {
        $client = self::createClient();
        self::givenUser(withApp: true);
        $link = '/passwort/neu/'.self::issue(TokenPurpose::Reset);

        $crawler = $client->request('GET', $link);
        self::assertStringNotContainsString('Sicherheit', $crawler->filter('.ib-flow')->text(), 'Kein Schritt zum Faktor');

        self::choosePassword($client, $link);

        self::assertResponseRedirects('/login', null, 'Nach dem Passwort ist der Ablauf fertig');
        self::assertSame(SecondFactor::App, self::user()->secondFactor()->kind());
        self::assertSame(self::SECRET, self::user()->secondFactor()->secret());

        $client->request('POST', $link, ['_token' => 'egal', 'choose' => 'app']);
        self::assertSelectorTextContains('.ib-heading', 'nicht mehr gültig');
    }

    /**
     * Mit dem neuen Passwort ist der Link verbraucht — auch wenn danach noch
     * der zweite Faktor kommt. Weiter geht es nur in der Sitzung, die das
     * Passwort gesetzt hat.
     */
    public function testTheResetLinkIsSpentWithTheNewPassword(): void
    {
        $client = self::createClient();
        self::givenUser(withApp: false);
        $link = '/passwort/neu/'.self::issue(TokenPurpose::Reset);

        self::choosePassword($client, $link);
        self::assertResponseRedirects($link);

        $client->getCookieJar()->clear();
        $client->request('GET', $link);
        self::assertSelectorTextContains('.ib-heading', 'nicht mehr gültig', 'Ein mitgelesener Link setzt kein zweites Passwort');
    }

    public function testTheSessionThatSetThePasswordFinishesTheReset(): void
    {
        $client = self::createClient();
        self::givenUser(withApp: false);
        $link = '/passwort/neu/'.self::issue(TokenPurpose::Reset);

        self::choosePassword($client, $link);
        $client->followRedirect();
        self::assertSelectorExists('button[name="choose"][value="app"]');

        $client->request('POST', $link, ['_token' => self::tokenFrom($client, $link), 'skip' => '1']);

        self::assertResponseRedirects('/login');
        $client->request('GET', $link);
        self::assertSelectorTextContains('.ib-heading', 'nicht mehr gültig');
    }

    /** Eine offene Sitzung am fremden Bildschirm ersetzt den Faktor nicht. */
    public function testAnOpenSessionDoesNotReplaceTheSecondFactor(): void
    {
        $client = self::signedInAs();
        // Das Formular zum Einrichten gibt es nur ohne Faktor — der Token
        // stammt also von vorher, wie bei jemandem, der die Seite offen hat.
        $token = self::tokenFrom($client, '/mein-konto?abschnitt=faktor', 'form[action$="/mein-konto/faktor"] input[name="_token"]');
        $me = self::testUser();
        $me->useSecondFactor(SecondFactorSettings::app(self::SECRET));
        self::users()->save($me);

        $client->request('POST', '/mein-konto/faktor', ['_token' => $token, 'choose' => 'app']);

        self::assertSelectorTextContains('.ib-flash--error', 'schon ein zweiter Faktor');
        self::assertSame(self::SECRET, self::testUser()->secondFactor()->secret());
    }

    /** Abschalten ist gebremst wie die Anmeldung — sonst liesse sich der Code hier durchprobieren. */
    public function testTurningOffIsBrakedLikeSigningIn(): void
    {
        $client = self::signedInAs();
        // Die erste Anfrage laeuft noch im Kernel, der das Konto angelegt
        // hat; erst danach sieht die Seite, was in der Datenbank steht.
        $client->request('GET', '/');
        $me = self::testUser();
        $me->useSecondFactor(SecondFactorSettings::app(self::SECRET));
        self::users()->save($me);

        for ($attempt = 0; $attempt < 6; ++$attempt) {
            $client->request('POST', '/mein-konto/faktor/aus', [
                '_token' => self::tokenFrom($client, '/mein-konto?abschnitt=faktor', 'form[action$="/faktor/aus"] input[name="_token"]'),
                'code' => '000000',
            ]);
        }

        $client->followRedirect();
        self::assertSelectorTextContains('.ib-flash--error', 'Zu viele Versuche');
        self::assertSame(SecondFactor::App, self::testUser()->secondFactor()->kind());
    }

    /**
     * Beim Faktor E-Mail kommt der Code zum Abschalten von der Kontoseite.
     * Vorher gab es keinen Weg zu ihm, und der Faktor liess sich nicht mehr
     * abschalten.
     */
    public function testTheEmailFactorSendsItsOwnCodeForTurningOff(): void
    {
        $client = self::signedInAs();
        $client->request('GET', '/');
        $me = self::testUser();
        $me->useSecondFactor(SecondFactorSettings::email());
        self::users()->save($me);
        $form = 'form[action$="/faktor/aus"] input[name="_token"]';

        $crawler = $client->request('GET', '/mein-konto?abschnitt=faktor');
        self::assertCount(1, $crawler->filter('form[action$="/faktor/aus"] button[name="send"]'));

        $client->request('POST', '/mein-konto/faktor/aus', ['_token' => self::tokenFrom($client, '/mein-konto?abschnitt=faktor', $form), 'send' => '1']);
        $client->followRedirect();
        self::assertSelectorTextContains('.ib-flash--success', 'unterwegs');
        self::assertSame(SecondFactor::Email, self::testUser()->secondFactor()->kind(), 'Schicken schaltet nichts ab');

        $code = self::issueFor(self::testUser(), TokenPurpose::SecondFactor);
        $client->request('POST', '/mein-konto/faktor/aus', ['_token' => self::tokenFrom($client, '/mein-konto?abschnitt=faktor', $form), 'code' => $code]);

        self::assertSame(SecondFactor::None, self::testUser()->secondFactor()->kind());
    }

    /** Wer deaktiviert wird, ist sofort draussen — nicht erst bei der naechsten Anmeldung. */
    public function testADeactivatedAccountIsSignedOutAtOnce(): void
    {
        $client = self::signedInAs();
        $client->request('GET', '/');
        self::assertResponseIsSuccessful();

        $me = self::testUser();
        $me->deactivate();
        self::users()->save($me);

        $client->request('GET', '/');
        self::assertResponseRedirects('/login');

        self::users()->save(self::reactivated());
        $client->request('GET', '/');
        self::assertResponseRedirects('/login', null, 'Die Sitzung ist weg, nicht nur angehalten');
    }

    /** Eine fremde Seite meldet niemanden ab — ohne Token aus dem eigenen Formular geht es nicht. */
    public function testSigningOutNeedsTheTokenOfTheForm(): void
    {
        $client = self::signedInAs();

        $client->request('GET', '/logout');
        self::assertResponseStatusCodeSame(405);

        $client->request('POST', '/logout');
        self::assertResponseStatusCodeSame(403);

        $client->request('GET', '/');
        self::assertResponseIsSuccessful('Noch angemeldet');
    }

    public function testANewPasswordRevokesOpenLinks(): void
    {
        self::bootKernel();
        self::givenUser(withApp: false);
        $reset = self::issue(TokenPurpose::Reset);
        $change = self::issue(TokenPurpose::EmailChange);

        $setUp = self::getContainer()->get(SetUpAccount::class);
        self::assertInstanceOf(SetUpAccount::class, $setUp);
        self::assertNull($setUp->choosePassword(self::user(), self::NEW_PASSWORD, self::NEW_PASSWORD));

        $tokens = self::getContainer()->get(TokenRepository::class);
        self::assertInstanceOf(TokenRepository::class, $tokens);
        self::assertNull($tokens->findUsable($reset, TokenPurpose::Reset, self::now()));
        self::assertNull($tokens->findUsable($change, TokenPurpose::EmailChange, self::now()));
    }

    /** `OPFER@…` und ` opfer@…` sind dasselbe Postfach und teilen sich eine Bremse. */
    public function testTheResetBrakeIgnoresHowTheAddressIsWritten(): void
    {
        $client = self::createClient();
        self::givenUser(withApp: false);

        foreach (['uebernahme@example.org', 'UEBERNAHME@example.org', ' Uebernahme@Example.org', 'uebernahme@EXAMPLE.ORG '] as $spelling) {
            $client->request('POST', '/passwort/vergessen', [
                '_token' => self::tokenFrom($client, '/passwort/vergessen'),
                'email' => $spelling,
            ]);
        }

        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $issued = $entityManager->createQuery('SELECT COUNT(t.id) FROM '.SignInToken::class.' t WHERE t.userId = :user AND t.purpose = :purpose')
            ->setParameter('user', self::user()->id())
            ->setParameter('purpose', TokenPurpose::Reset)
            ->getSingleScalarResult();

        self::assertSame(3, (int) $issued);
    }

    protected static function testEmail(): string
    {
        return 'uebernahme-sitzung@example.org';
    }

    private static function choosePassword(KernelBrowser $client, string $link): void
    {
        $client->request('POST', $link, [
            '_token' => self::tokenFrom($client, $link),
            'password' => self::NEW_PASSWORD,
            'repeated' => self::NEW_PASSWORD,
        ]);
    }

    private static function tokenFrom(KernelBrowser $client, string $page, string $selector = 'input[name="_token"]'): string
    {
        return (string) $client->request('GET', $page)->filter($selector)->first()->attr('value');
    }

    private static function givenUser(bool $withApp): void
    {
        self::removeUser();
        $hasher = self::getContainer()->get(UserPasswordHasherInterface::class);
        self::assertInstanceOf(UserPasswordHasherInterface::class, $hasher);

        $user = new User(self::users()->nextNumber(), Email::fromString(self::EMAIL));
        $user->nameYourself(PersonName::of('Uwe', 'Uebernahme'));
        $user->changePassword($hasher->hashPassword($user, self::PASSWORD));
        $user->activate();

        if ($withApp) {
            $user->useSecondFactor(SecondFactorSettings::app(self::SECRET));
        }

        self::users()->save($user);
    }

    private static function issue(TokenPurpose $purpose): string
    {
        return self::issueFor(self::user(), $purpose);
    }

    private static function issueFor(User $user, TokenPurpose $purpose): string
    {
        $tokens = self::getContainer()->get(TokenRepository::class);
        self::assertInstanceOf(TokenRepository::class, $tokens);
        $hasher = self::getContainer()->get(TokenHasher::class);
        self::assertInstanceOf(TokenHasher::class, $hasher);

        $issued = SignInToken::issue($user->id(), $purpose, self::now(), $hasher, TokenPurpose::EmailChange === $purpose ? 'neu@example.org' : null);
        $tokens->issue($issued->token, self::now());

        return $issued->plain;
    }

    private static function now(): DateTimeImmutable
    {
        $clock = self::getContainer()->get(ClockInterface::class);
        self::assertInstanceOf(ClockInterface::class, $clock);

        return $clock->now();
    }

    private static function user(): User
    {
        $user = self::users()->findByEmail(Email::fromString(self::EMAIL));
        self::assertInstanceOf(User::class, $user);

        return $user;
    }

    private static function testUser(): User
    {
        $user = self::users()->findByEmail(Email::fromString(self::testEmail()));
        self::assertInstanceOf(User::class, $user);

        return $user;
    }

    private static function reactivated(): User
    {
        $me = self::testUser();
        $me->reactivate();
        $me->activate();

        return $me;
    }

    private static function users(): UserRepository
    {
        $users = self::getContainer()->get(UserRepository::class);
        self::assertInstanceOf(UserRepository::class, $users);

        return $users;
    }

    private static function removeUser(): void
    {
        if (null === self::$kernel) {
            return;
        }

        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $entityManager->createQuery('DELETE FROM '.User::class.' u WHERE u.email = :email')
            ->setParameter('email', self::EMAIL)
            ->execute();
    }
}
