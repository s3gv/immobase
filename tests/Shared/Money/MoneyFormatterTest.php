<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Tests\Shared\Money;

use App\Shared\Money\Money;
use App\Shared\Money\MoneyFormatter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class MoneyFormatterTest extends TestCase
{
    #[DataProvider('amounts')]
    public function testWritesTheAmountInTheStyleOfTheLanguage(int $cents, string $locale, string $expected): void
    {
        self::assertSame($expected, MoneyFormatter::format(Money::fromCents($cents), $locale));
    }

    /**
     * @return iterable<string, array{int, string, string}>
     */
    public static function amounts(): iterable
    {
        yield 'deutsch' => [125000, 'de', "1.250,00\u{00A0}€"];
        yield 'englisch' => [125000, 'en', "1,250.00\u{00A0}€"];
        yield 'deutsch, Million' => [100000000, 'de', "1.000.000,00\u{00A0}€"];
        yield 'englisch, Million' => [100000000, 'en', "1,000,000.00\u{00A0}€"];
        yield 'ohne Tausender' => [1250, 'de', "12,50\u{00A0}€"];
        yield 'negativ' => [-550, 'de', "-5,50\u{00A0}€"];
        yield 'null' => [0, 'de', "0,00\u{00A0}€"];
        yield 'nur Cent' => [5, 'de', "0,05\u{00A0}€"];
        yield 'Sprache mit Region' => [125000, 'de_AT', "1.250,00\u{00A0}€"];
        yield 'unbekannte Sprache fällt auf Deutsch zurück' => [125000, 'fr', "1.250,00\u{00A0}€"];
    }

    /**
     * Money laesst jeden Integer zu, also muss die Anzeige auch den Rand des
     * Wertebereichs schreiben koennen. abs() liefert dort eine
     * Fliesskommazahl — deshalb rechnet die Anzeige gar nicht mehr, sondern
     * setzt Ziffern zusammen.
     */
    public function testWritesEvenTheSmallestPossibleAmount(): void
    {
        self::assertSame(
            "-92.233.720.368.547.758,08\u{00A0}€",
            MoneyFormatter::format(Money::fromCents(\PHP_INT_MIN), 'de'),
        );
    }

    public function testWritesTheLargestPossibleAmount(): void
    {
        self::assertSame(
            "92.233.720.368.547.758,07\u{00A0}€",
            MoneyFormatter::format(Money::fromCents(\PHP_INT_MAX), 'de'),
        );
    }

    public function testWritesThePlainNumberForInputFields(): void
    {
        self::assertSame('1.250,00', MoneyFormatter::formatNumber(Money::fromCents(125000), 'de'));
        self::assertSame('1,250.00', MoneyFormatter::formatNumber(Money::fromCents(125000), 'en'));
    }

    /**
     * Die Zusage des Paars: kein Betrag, den die Anzeige schreiben kann,
     * bleibt der Eingabe verschlossen — auch nicht an den Raendern.
     */
    public function testWhatItWritesCanBeReadBackUnchanged(): void
    {
        foreach (['de', 'en'] as $locale) {
            foreach ([0, 5, 1250, 125000, 100000000, -550, \PHP_INT_MAX, -\PHP_INT_MAX, \PHP_INT_MIN] as $cents) {
                $written = MoneyFormatter::format(Money::fromCents($cents), $locale);

                self::assertSame(
                    $cents,
                    \App\Shared\Money\MoneyInput::parse($written)->cents(),
                    \sprintf('%s in %s', $written, $locale),
                );
            }
        }
    }
}
