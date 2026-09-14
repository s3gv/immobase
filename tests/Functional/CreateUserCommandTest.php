<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Module\Auth\Domain\User;
use App\Module\Auth\Domain\UserRepository;
use App\Shared\Contact\Email;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Das erste Konto, wie install.sh es anlegt.
 *
 * Das Passwort kommt ueber die Standardeingabe und nie als Argument: ein
 * Argument steht in der Prozessliste, fuer jeden im Container lesbar. Und es
 * gelten dieselben Regeln wie in der Oberflaeche — das erste Konto ist das,
 * an dem alles haengt.
 */
final class CreateUserCommandTest extends KernelTestCase
{
    private const string EMAIL = 'erstes.konto@example.org';

    protected function tearDown(): void
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $entityManager->createQuery('DELETE FROM '.User::class.' u WHERE u.email = :email')
            ->setParameter('email', self::EMAIL)
            ->execute();

        parent::tearDown();
    }

    public function testThePasswordComesFromStandardInputAndTheNameIsOptional(): void
    {
        $tester = self::command();
        $tester->setInputs(['Ein-langer-Satz-als-Passwort-2026']);

        $status = $tester->execute(['email' => self::EMAIL, '--admin' => true], ['interactive' => false]);

        self::assertSame(Command::SUCCESS, $status, $tester->getDisplay());
        $user = self::users()->findByEmail(Email::fromString(self::EMAIL));
        self::assertInstanceOf(User::class, $user);
        self::assertSame(self::EMAIL, $user->displayName(), 'Ohne Namen steht die Adresse da');
        self::assertTrue($user->isAdministrator());
    }

    public function testAWeakPasswordIsRefusedWithTheReason(): void
    {
        $tester = self::command();
        $tester->setInputs(['kurz']);

        $status = $tester->execute(['email' => self::EMAIL], ['interactive' => false]);

        self::assertSame(Command::INVALID, $status);
        self::assertStringContainsString('at least 12 characters', $tester->getDisplay());
        self::assertNull(self::users()->findByEmail(Email::fromString(self::EMAIL)));
    }

    public function testANameIsTakenWhenGiven(): void
    {
        $tester = self::command();
        $tester->setInputs(['Ein-langer-Satz-als-Passwort-2026']);

        $tester->execute(['email' => self::EMAIL, 'givenName' => 'Erika', 'familyName' => 'Muster'], ['interactive' => false]);

        self::assertSame('Erika Muster', self::users()->findByEmail(Email::fromString(self::EMAIL))?->displayName());
    }

    private static function command(): CommandTester
    {
        return new CommandTester((new Application(self::bootKernel()))->find('immobase:user:create'));
    }

    private static function users(): UserRepository
    {
        $users = self::getContainer()->get(UserRepository::class);
        self::assertInstanceOf(UserRepository::class, $users);

        return $users;
    }
}
