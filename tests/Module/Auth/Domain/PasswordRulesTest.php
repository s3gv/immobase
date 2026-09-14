<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Tests\Module\Auth\Domain;

use App\Module\Auth\Domain\PasswordRules;
use App\Module\Auth\Domain\PasswordStrength;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PasswordRulesTest extends TestCase
{
    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function passwords(): iterable
    {
        yield 'zwölf Kleinbuchstaben' => ['abcdefghijkl', false];
        yield 'zwölf gemischt' => ['aB3xQ7mK9pLz', true];
        yield 'ein ganzer Satz' => ['die katze schlaeft auf dem sofa', true];
        yield 'kurz und wild' => ['aB3!x', false];
    }

    /**
     * Laenge traegt weiter als Sonderzeichen: ein ganzer Satz kommt durch,
     * zwoelf Kleinbuchstaben nicht. Genau das ist die Absicht — die Pflicht zu
     * Ziffer und Sonderzeichen erzeugt "Passwort1!".
     */
    #[DataProvider('passwords')]
    public function testJudgesStrengthByLengthAndVariety(string $password, bool $expected): void
    {
        self::assertSame($expected, PasswordStrength::isStrongEnough($password));
    }

    public function testFindsTheOwnNameInAPassword(): void
    {
        $personal = ['Erika', 'Muster', 'erika@example.org'];

        self::assertTrue(PasswordRules::containsPersonalData('Erika-2026-Berlin', $personal));
        self::assertTrue(PasswordRules::containsPersonalData('xxerikaxx', $personal), 'Gross- und Kleinschreibung egal');
        self::assertFalse(PasswordRules::containsPersonalData('die katze schlaeft', $personal));
    }

    /**
     * Kurze Bestandteile zaehlen nicht: "Li" steckt in unzaehligen harmlosen
     * Passwoertern, und die Regel wuerde dann nur noch nerven.
     */
    public function testIgnoresVeryShortNameParts(): void
    {
        self::assertFalse(PasswordRules::containsPersonalData('lieblingsplatz am see', ['Li', 'Wu']));
    }

    /**
     * Die Adresse zerfaellt in ihre Teile: wer "example" im Passwort hat,
     * verraet damit nicht sein Konto — der lokale Teil dagegen schon.
     */
    public function testSplitsAddressesIntoParts(): void
    {
        self::assertTrue(PasswordRules::containsPersonalData('erika ist mein passwort', ['erika@example.org']));
    }

    public function testTheLeakRuleOnlyAppearsWhenTheCheckIsOn(): void
    {
        self::assertContains('user.password.rule.leaks', PasswordRules::explained(true));
        self::assertNotContains('user.password.rule.leaks', PasswordRules::explained(false));
    }
}
