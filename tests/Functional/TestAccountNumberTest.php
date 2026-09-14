<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Module\Auth\Domain\User;
use App\Module\Auth\Domain\UserRepository;
use App\Shared\Contact\Email;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Woher das Testkonto seine Benutzernummer nimmt.
 *
 * Die Nummer ist eindeutig, und sie wurde einmal geraten: eine Zufallszahl aus
 * knapp neuntausend. Das geht fast immer gut. Fast — wer eine Nummer zieht,
 * die schon vergeben ist, laeuft in den eindeutigen Index, und der Test, dem
 * das passiert, scheitert an etwas, das mit ihm nichts zu tun hat. Nicht
 * reproduzierbar, weil beim naechsten Lauf eine andere Zahl faellt.
 *
 * Die Anwendung selbst raet nicht: sie fragt die Sequenz, und nextval() gibt
 * jede Nummer nur einmal heraus. Die Testanmeldung tut jetzt dasselbe, und
 * dieser Test haelt sie dabei fest.
 */
final class TestAccountNumberTest extends WebTestCase
{
    use SignsIn;

    protected function tearDown(): void
    {
        self::removeTestUser();

        parent::tearDown();
    }

    public function testTheAccountTakesItsNumberFromTheSequence(): void
    {
        self::signedInAs();

        $users = self::getContainer()->get(UserRepository::class);
        self::assertInstanceOf(UserRepository::class, $users);

        $account = $users->findByEmail(Email::fromString(self::testEmail()));
        self::assertInstanceOf(User::class, $account);

        // Seit der Anmeldung hat niemand sonst die Sequenz angefasst. Die
        // naechste Nummer muss deshalb genau die darauf folgende sein — eine
        // geratene Zahl traefe das nur zufaellig.
        self::assertSame(
            $account->number() + 1,
            $users->nextNumber(),
            'Die Nummer des Testkontos kommt nicht aus der Sequenz, sondern aus dem Zufall',
        );
    }

    protected static function testEmail(): string
    {
        return 'test-account-number@example.org';
    }
}
