<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Module\Auth\Domain\RecoveryCode;
use App\Module\Auth\Domain\RecoveryCodeRepository;
use App\Module\Auth\Domain\SecondFactorSettings;
use App\Module\Auth\Domain\TokenHasher;
use App\Module\Auth\Domain\Totp;
use App\Module\Auth\Domain\User;
use App\Module\Auth\Domain\UserRepository;
use App\Shared\Contact\Email;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Die Anmeldung mit zweitem Faktor.
 *
 * Der Punkt, an dem alles haengt: zwischen richtigem Passwort und richtigem
 * Code darf keine Sitzung entstehen. Sonst waere der zweite Faktor eine
 * Verzierung.
 */
final class SecondFactorSignInTest extends WebTestCase
{
    use ForgetsRateLimits;

    private const string EMAIL = 'zweifaktor@example.org';
    private const string PASSWORD = 'die katze schlaeft auf dem sofa';
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

        parent::tearDown();
    }

    public function testTheRightPasswordAloneDoesNotSignAnyoneIn(): void
    {
        $client = self::createClient();
        self::givenUserWithApp();

        self::signIn($client);

        self::assertResponseRedirects('/anmelden/bestaetigen');

        // Der eigentliche Beweis: eine geschuetzte Seite bleibt zu.
        $client->request('GET', '/');

        self::assertResponseRedirects('/login');
    }

    public function testTheCodeCompletesTheSignIn(): void
    {
        $client = self::createClient();
        self::givenUserWithApp();
        self::signIn($client);
        $client->followRedirect();

        self::submitCode($client, Totp::codeForStep(self::SECRET, Totp::step(time())));

        self::assertResponseRedirects('/');

        $client->request('GET', '/');

        self::assertResponseIsSuccessful();
    }

    public function testAWrongCodeKeepsTheDoorClosed(): void
    {
        $client = self::createClient();
        self::givenUserWithApp();
        self::signIn($client);
        $client->followRedirect();

        self::submitCode($client, '000000');

        self::assertSelectorTextContains('.ib-flash--error', 'stimmt nicht');

        $client->request('GET', '/');

        self::assertResponseRedirects('/login');
    }

    /**
     * Ein abgefangener Code soll innerhalb seiner dreissig Sekunden kein
     * zweites Mal funktionieren.
     */
    public function testTheSameCodeDoesNotWorkTwice(): void
    {
        $code = Totp::codeForStep(self::SECRET, Totp::step(time()));

        $first = self::createClient();
        self::givenUserWithApp();
        self::signIn($first);
        $first->followRedirect();
        self::submitCode($first, $code);

        self::assertResponseRedirects('/');

        self::ensureKernelShutdown();
        $second = self::createClient();
        self::signIn($second);
        $second->followRedirect();
        self::submitCode($second, $code);

        self::assertSelectorTextContains('.ib-flash--error', 'stimmt nicht');
    }

    public function testARecoveryCodeGetsYouInOnceAndOnlyOnce(): void
    {
        $client = self::createClient();
        $user = self::givenUserWithApp();

        $codes = self::getContainer()->get(RecoveryCodeRepository::class);
        self::assertInstanceOf(RecoveryCodeRepository::class, $codes);

        [$stored, $plain] = RecoveryCode::issue($user->id(), self::hasher());
        $codes->replaceAll($user->id(), $stored);
        $code = $plain[0] ?? '';

        self::signIn($client);
        $client->followRedirect();
        self::submitCode($client, $code, recovery: true);

        self::assertResponseRedirects('/');

        self::ensureKernelShutdown();
        $again = self::createClient();
        self::signIn($again);
        $again->followRedirect();
        self::submitCode($again, $code, recovery: true);

        self::assertSelectorTextContains('.ib-flash--error', 'stimmt nicht');
    }

    /**
     * Ohne Bremse waere ein sechsstelliger Code in Minuten durchprobiert.
     */
    public function testTooManyAttemptsAreStopped(): void
    {
        $client = self::createClient();
        self::givenUserWithApp();
        self::signIn($client);
        $client->followRedirect();

        for ($attempt = 0; $attempt < 5; ++$attempt) {
            self::submitCode($client, '000000');
        }

        self::submitCode($client, '000000');

        self::assertSelectorTextContains('.ib-flash--error', 'Zu viele Versuche');
    }

    /**
     * Wenn die Wiederherstellungscodes zur Neige gehen, sagt die Anwendung es
     * — nach der Anmeldung, nicht davor. Davor waere es eine Huerde mehr in
     * einem Moment, in dem jemand hereinwill.
     */
    public function testARunningOutOfRecoveryCodesIsMentionedAfterSigningIn(): void
    {
        $client = self::createClient();
        $user = self::givenUserWithApp();

        $codes = self::getContainer()->get(RecoveryCodeRepository::class);
        self::assertInstanceOf(RecoveryCodeRepository::class, $codes);

        // Zwei uebrig: die Schwelle, ab der es knapp wird.
        [$stored] = RecoveryCode::issue($user->id(), self::hasher());
        $codes->replaceAll($user->id(), \array_slice($stored, 0, 2));

        self::signIn($client);
        $client->followRedirect();
        self::submitCode($client, Totp::codeForStep(self::SECRET, Totp::step(time())));
        $client->followRedirect();

        self::assertSelectorTextContains('.ib-flash--error', 'gehen zur Neige');
    }

    /**
     * Die Bremse haengt am Konto, nicht an der Sitzung.
     *
     * Sonst holt sich, wer das Passwort kennt, einfach eine frische Sitzung
     * und bekommt wieder fuenf Versuche — bei einem sechsstelligen Code ist
     * das kein Schutz, sondern eine Verzoegerung.
     */
    public function testAFreshSessionDoesNotResetTheBrake(): void
    {
        $first = self::createClient();
        self::givenUserWithApp();
        self::signIn($first);
        $first->followRedirect();

        for ($attempt = 0; $attempt < 5; ++$attempt) {
            self::submitCode($first, '000000');
        }

        // Neue Sitzung, dasselbe Konto: der Zaehler laeuft weiter.
        self::ensureKernelShutdown();
        $second = self::createClient();
        self::signIn($second);
        $second->followRedirect();
        self::submitCode($second, '000000');

        self::assertSelectorTextContains('.ib-flash--error', 'Zu viele Versuche');
    }

    private static function signIn(KernelBrowser $client): void
    {
        $crawler = $client->request('GET', '/login');
        $client->submit($crawler->selectButton('Anmelden')->form([
            '_username' => self::EMAIL,
            '_password' => self::PASSWORD,
        ]));
    }

    private static function submitCode(KernelBrowser $client, string $code, bool $recovery = false): void
    {
        $token = $client->request('GET', '/anmelden/bestaetigen')
            ->filter('input[name="_token"]')->attr('value');

        $fields = ['_token' => (string) $token, 'code' => $code];

        if ($recovery) {
            $fields['recovery'] = '1';
        }

        $client->request('POST', '/anmelden/bestaetigen', $fields);

        // Ein falscher Code fuehrt zurueck auf dieselbe Seite — die Meldung
        // steht dort, nicht in der Weiterleitung.
        if ($client->getResponse()->isRedirect('/anmelden/bestaetigen')) {
            $client->followRedirect();
        }
    }

    private static function hasher(): TokenHasher
    {
        $hasher = self::getContainer()->get(TokenHasher::class);
        self::assertInstanceOf(TokenHasher::class, $hasher);

        return $hasher;
    }

    private static function givenUserWithApp(): User
    {
        self::removeUser();

        $users = self::getContainer()->get(UserRepository::class);
        self::assertInstanceOf(UserRepository::class, $users);
        $hasher = self::getContainer()->get(UserPasswordHasherInterface::class);
        self::assertInstanceOf(UserPasswordHasherInterface::class, $hasher);

        $user = new User($users->nextNumber(), Email::fromString(self::EMAIL));
        $user->changePassword($hasher->hashPassword($user, self::PASSWORD));
        $user->activate();
        $user->useSecondFactor(SecondFactorSettings::app(self::SECRET));
        $users->save($user);

        return $user;
    }

    private static function removeUser(): void
    {
        if (null === self::$kernel) {
            return;
        }

        $entityManager = self::getContainer()->get(EntityManagerInterface::class);

        if (!$entityManager instanceof EntityManagerInterface) {
            return;
        }

        $entityManager->createQuery('DELETE FROM '.User::class.' u WHERE u.email = :email')
            ->setParameter('email', self::EMAIL)
            ->execute();
    }
}
