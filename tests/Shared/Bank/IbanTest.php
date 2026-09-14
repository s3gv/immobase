<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Tests\Shared\Bank;

use App\Shared\Bank\Iban;
use App\Shared\Bank\NotAnIban;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Die IBAN und ihre Pruefziffer.
 *
 * Der Zahlendreher ist der haeufige Fehler, und eine Ueberweisung auf eine
 * formal gueltige, aber falsche IBAN merkt niemand, bis sie zurueckkommt.
 */
final class IbanTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function accepted(): iterable
    {
        yield 'deutsche IBAN' => ['DE89370400440532013000', 'DE89370400440532013000'];
        yield 'in Vierergruppen getippt' => ['DE89 3704 0044 0532 0130 00', 'DE89370400440532013000'];
        yield 'klein geschrieben' => ['de89370400440532013000', 'DE89370400440532013000'];
        yield 'mit Leerzeichen aussen' => ['  DE89370400440532013000  ', 'DE89370400440532013000'];
        yield 'britische IBAN mit Buchstaben' => ['GB82WEST12345698765432', 'GB82WEST12345698765432'];
        yield 'kurze IBAN aus Norwegen' => ['NO9386011117947', 'NO9386011117947'];
    }

    #[DataProvider('accepted')]
    public function testNormalisesAndAccepts(string $input, string $expected): void
    {
        self::assertSame($expected, Iban::fromString($input)->toString());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function refused(): iterable
    {
        yield 'Zahlendreher in der Kontonummer' => ['DE89370400440532031000'];
        yield 'falsche Prüfziffer' => ['DE90370400440532013000'];
        yield 'zu kurz' => ['DE8937040044'];
        yield 'ohne Länderkennung' => ['8937370400440532013000'];
        yield 'mit Sonderzeichen' => ['DE89-3704-0044-0532-0130-00'];
        yield 'leer' => [''];
    }

    #[DataProvider('refused')]
    public function testRefusesWhatIsNotAnIban(string $input): void
    {
        $this->expectException(NotAnIban::class);

        Iban::fromString($input);
    }

    /** So steht sie auf jedem Kontoauszug. */
    public function testIsShownInGroupsOfFour(): void
    {
        self::assertSame(
            'DE89 3704 0044 0532 0130 00',
            Iban::fromString('DE89370400440532013000')->formatted(),
        );
    }

    /** Keine Angabe ist kein Fehler. */
    public function testAnEmptyValueIsNothingAtAll(): void
    {
        self::assertNull(Iban::orNull('   '));
        self::assertNotNull(Iban::orNull('DE89370400440532013000'));
    }
}
