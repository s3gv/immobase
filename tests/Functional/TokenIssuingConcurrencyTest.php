<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Module\Auth\Domain\SignInToken;
use App\Module\Auth\Domain\TokenHasher;
use App\Module\Auth\Domain\TokenPurpose;
use App\Module\Auth\Domain\TokenRepository;
use App\Module\Auth\Domain\User;
use App\Module\Auth\Domain\UserRepository;
use App\Shared\Contact\Email;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\ClockInterface;

/**
 * Zwei Ausstellungen zur selben Zeit — mit zwei echten Verbindungen.
 *
 * Die uebrigen Tests koennen das nicht zeigen: sie laufen nacheinander auf
 * einer Verbindung, und dort gibt es kein Wettrennen. Genau deshalb blieb ein
 * Loch offen, das aussah, als waere es zu: die Transaktion schuetzte nur,
 * solange schon ein offener Schluessel dalag, den das Entwerten sperren
 * konnte. Bei der *ersten* Anforderung traf das UPDATE keine Zeile, sperrte
 * nichts, und zwei gleichzeitige Anfragen legten beide einen gueltigen
 * Schluessel an.
 *
 * Der Test haelt die Sperre auf der zweiten Verbindung fest und laesst die
 * erste hineinlaufen. Ohne die Sperre kaeme sie durch — und genau das waere
 * der Fehler.
 */
final class TokenIssuingConcurrencyTest extends KernelTestCase
{
    use UsesASecondConnection;

    private const string EMAIL = 'gleichzeitig@example.org';

    protected function tearDown(): void
    {
        // Aufgeraeumt wird ueber die zweite Verbindung: nach einer
        // fehlgeschlagenen Transaktion ist der EntityManager geschlossen.
        if (null !== $this->other) {
            if ($this->other->isTransactionActive()) {
                $this->other->rollBack();
            }

            $this->other->executeStatement(
                'DELETE FROM auth_token WHERE user_id IN (SELECT id FROM auth_user WHERE email = ?)',
                [self::EMAIL],
            );
            $this->other->executeStatement('DELETE FROM auth_user WHERE email = ?', [self::EMAIL]);
            $this->closeSecondConnection();
        }

        parent::tearDown();
    }

    public function testAFirstIssuingWaitsForAnotherFirstIssuing(): void
    {
        $user = self::givenUser();
        $other = $this->secondConnection();

        // Die zweite Verbindung tut, was das Ausstellen zuerst tut: sie
        // sperrt die Kontozeile — und haelt sie fest.
        $other->beginTransaction();
        $other->executeQuery('SELECT id FROM auth_user WHERE id = ? FOR UPDATE', [$user->id()]);

        $blocked = $this->tryToIssueFor($user);

        self::assertTrue($blocked, 'Die zweite Ausstellung lief an der ersten vorbei');

        $other->rollBack();

        $left = $other->fetchOne(
            'SELECT COUNT(*) FROM auth_token WHERE user_id = ?',
            [$user->id()],
        );

        self::assertSame(0, is_numeric($left) ? (int) $left : -1, 'Und hat auch nichts hinterlassen');
    }

    /**
     * @return bool ob die Ausstellung an der Sperre haengen blieb
     */
    private function tryToIssueFor(User $user): bool
    {
        self::stopWaitingQuickly();

        $now = self::clock()->now();
        $issued = SignInToken::issue($user->id(), TokenPurpose::Reset, $now, self::hasher());

        try {
            self::tokens()->issue($issued->token, $now);
        } catch (\Doctrine\DBAL\Exception) {
            // Die Datenbank hat sie warten lassen, bis die Geduld zu Ende war.
            return true;
        }

        return false;
    }

    private static function givenUser(): User
    {
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
}
