<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Module\Auth\Domain\RecoveryCode;
use App\Module\Auth\Domain\RecoveryCodeRepository;
use App\Module\Auth\Domain\SignInToken;
use App\Module\Auth\Domain\TokenHasher;
use App\Module\Auth\Domain\TokenPurpose;
use App\Module\Auth\Domain\TokenRepository;
use App\Module\Auth\Domain\User;
use App\Module\Auth\Domain\UserRepository;
use App\Shared\Contact\Email;
use DateInterval;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\ClockInterface;

/**
 * "Einmal verwendbar" — auch dann, wenn zwei Anfragen gleichzeitig kommen.
 *
 * Geprueft wird die Verschraenkung, die in der Wirklichkeit vorkommt: zwei
 * Anfragen lesen denselben offenen Schluessel, bevor eine von beiden ihn
 * entwertet. Genau das simulieren die Tests, indem sie den Datensatz zweimal
 * frisch aus der Datenbank holen und erst danach entwerten.
 *
 * Vorher schrieben beide "benutzt" und beide hielten sich fuer den ersten.
 * Jetzt entscheidet eine bedingte Abfrage, und nur eine bekommt true.
 */
final class OneTimeUseTest extends KernelTestCase
{
    private const string EMAIL = 'einmalig@example.org';

    protected function tearDown(): void
    {
        self::removeUser();

        parent::tearDown();
    }

    public function testTwoRequestsCannotBothSpendTheSameToken(): void
    {
        $user = self::givenUser();
        $tokens = self::tokens();
        $now = self::clock()->now();

        $issued = SignInToken::issue($user->id(), TokenPurpose::Invite, $now, self::hasher());
        $tokens->issue($issued->token, $now);

        // Beide "Anfragen" lesen den Schluessel, bevor eine ihn entwertet.
        $first = $tokens->findUsable($issued->plain, TokenPurpose::Invite, $now);
        self::detach();
        $second = $tokens->findUsable($issued->plain, TokenPurpose::Invite, $now);

        self::assertNotNull($first);
        self::assertNotNull($second);
        self::assertNotSame($first, $second, 'Zwei getrennt gelesene Datensätze');

        self::assertTrue($tokens->consume($first, $now), 'Der erste bekommt ihn');
        self::assertFalse($tokens->consume($second, $now), 'Der zweite geht leer aus');
    }

    public function testAnExpiredTokenCannotBeSpent(): void
    {
        $user = self::givenUser();
        $tokens = self::tokens();
        $now = self::clock()->now();

        $issued = SignInToken::issue($user->id(), TokenPurpose::Invite, $now, self::hasher());
        $tokens->issue($issued->token, $now);

        self::assertFalse(
            $tokens->consume($issued->token, $now->add(new DateInterval('P3D'))),
            'Nach 48 Stunden ist er fort',
        );
    }

    public function testAFreshTokenInvalidatesTheOpenOne(): void
    {
        $user = self::givenUser();
        $tokens = self::tokens();
        $now = self::clock()->now();

        $first = SignInToken::issue($user->id(), TokenPurpose::Invite, $now, self::hasher());
        $tokens->issue($first->token, $now);

        $second = SignInToken::issue($user->id(), TokenPurpose::Invite, $now, self::hasher());
        $tokens->issue($second->token, $now);

        self::detach();

        self::assertNull($tokens->findUsable($first->plain, TokenPurpose::Invite, $now), 'Der alte ist entwertet');
        self::assertNotNull($tokens->findUsable($second->plain, TokenPurpose::Invite, $now));
    }

    public function testTwoRequestsCannotBothSpendTheSameRecoveryCode(): void
    {
        $user = self::givenUser();
        $codes = self::recoveryCodes();
        $now = self::clock()->now();

        [$stored, $plain] = RecoveryCode::issue($user->id(), self::hasher());
        $codes->replaceAll($user->id(), $stored);

        $text = $plain[0] ?? '';
        $first = $codes->findUnused($user->id(), $text);
        self::detach();
        $second = $codes->findUnused($user->id(), $text);

        self::assertNotNull($first);
        self::assertNotNull($second);

        self::assertTrue($codes->consume($first, $now), 'Der erste bekommt ihn');
        self::assertFalse($codes->consume($second, $now), 'Der zweite geht leer aus');
        self::assertSame(9, $codes->countUnused($user->id()), 'Genau einer ist weg');
    }

    /** Trennt den Speicher von der Datenbank — sonst kommt derselbe Datensatz zurueck. */
    private static function detach(): void
    {
        self::entityManager()->clear();
    }

    private static function givenUser(): User
    {
        self::removeUser();

        $users = self::getContainer()->get(UserRepository::class);
        self::assertInstanceOf(UserRepository::class, $users);

        $user = new User($users->nextNumber(), Email::fromString(self::EMAIL));
        $users->save($user);

        return $user;
    }

    private static function tokens(): TokenRepository
    {
        $tokens = self::getContainer()->get(TokenRepository::class);
        self::assertInstanceOf(TokenRepository::class, $tokens);

        return $tokens;
    }

    private static function recoveryCodes(): RecoveryCodeRepository
    {
        $codes = self::getContainer()->get(RecoveryCodeRepository::class);
        self::assertInstanceOf(RecoveryCodeRepository::class, $codes);

        return $codes;
    }

    private static function hasher(): TokenHasher
    {
        $hasher = self::getContainer()->get(TokenHasher::class);
        self::assertInstanceOf(TokenHasher::class, $hasher);

        return $hasher;
    }

    private static function clock(): ClockInterface
    {
        $clock = self::getContainer()->get(ClockInterface::class);
        self::assertInstanceOf(ClockInterface::class, $clock);

        return $clock;
    }

    private static function entityManager(): EntityManagerInterface
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);

        return $entityManager;
    }

    private static function removeUser(): void
    {
        if (null === self::$kernel) {
            return;
        }

        self::entityManager()->createQuery('DELETE FROM '.User::class.' u WHERE u.email = :email')
            ->setParameter('email', self::EMAIL)
            ->execute();
    }
}
