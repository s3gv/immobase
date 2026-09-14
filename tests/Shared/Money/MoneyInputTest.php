<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Tests\Shared\Money;

use App\Shared\Money\MoneyInput;
use App\Shared\Money\UnreadableAmount;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class MoneyInputTest extends TestCase
{
    #[DataProvider('readableAmounts')]
    public function testReadsAnAmount(string $input, int $expectedCents): void
    {
        self::assertSame($expectedCents, MoneyInput::parse($input)->cents());
    }

    /**
     * @return iterable<string, array{string, int}>
     */
    public static function readableAmounts(): iterable
    {
        yield 'ganze Zahl' => ['1250', 125000];
        yield 'Komma als Dezimaltrenner' => ['1250,00', 125000];
        yield 'Punkt als Dezimaltrenner' => ['1250.00', 125000];
        yield 'eine Nachkommastelle mit Komma' => ['12,5', 1250];
        yield 'eine Nachkommastelle mit Punkt' => ['12.5', 1250];
        yield 'deutsche Schreibweise' => ['1.250,00', 125000];
        yield 'englische Schreibweise' => ['1,250.00', 125000];
        yield 'Tausenderpunkt ohne Nachkomma' => ['1.250', 125000];
        yield 'Tausenderkomma ohne Nachkomma' => ['1,250', 125000];
        yield 'mehrere Tausendergruppen' => ['1.250.000,50', 125000050];
        yield 'negativ' => ['-5,50', -550];
        yield 'ausdrücklich positiv' => ['+5,50', 550];
        yield 'Leerzeichen als Tausendertrenner' => ['1 250,00', 125000];
        yield 'geschütztes Leerzeichen' => ["1\u{00A0}250,00", 125000];
        yield 'Währungszeichen dahinter' => ['1.250,00 €', 125000];
        yield 'Währungszeichen davor' => ['€ 12,50', 1250];
        yield 'Währungskürzel' => ['12,50 EUR', 1250];
        yield 'führender Dezimaltrenner' => [',50', 50];
        yield 'null' => ['0', 0];
        yield 'null mit Nachkomma' => ['0,00', 0];
        yield 'nur Cent' => ['0,05', 5];
        yield 'größter darstellbarer Betrag' => ['92233720368547758,07', \PHP_INT_MAX];
        // PHP_INT_MIN hat einen Cent mehr Betrag als PHP_INT_MAX.
        yield 'kleinster darstellbarer Betrag' => ['-92233720368547758,08', \PHP_INT_MIN];
        yield 'ein Cent darüber' => ['-92233720368547758,07', -\PHP_INT_MAX];
        yield 'kleinster Betrag mit Tausendertrennern' => ['-92.233.720.368.547.758,08', \PHP_INT_MIN];
        yield 'führende Nullen' => ['007,50', 750];
    }

    #[DataProvider('unreadableAmounts')]
    public function testRefusesWhatItCannotRead(string $input): void
    {
        $this->expectException(UnreadableAmount::class);

        MoneyInput::parse($input);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function unreadableAmounts(): iterable
    {
        yield 'leer' => [''];
        yield 'nur Leerzeichen' => ['   '];
        yield 'Buchstaben' => ['zwölf'];
        yield 'Buchstaben zwischen Ziffern' => ['1a2'];
        yield 'zu viele Nachkommastellen' => ['1,2345'];
        yield 'doppelter Trenner' => ['1..2'];
        yield 'unvollständige Tausendergruppe' => ['1.2.3'];
        yield 'Trenner ohne Nachkommastellen' => ['12,'];
        yield 'nur ein Trenner' => ['.'];
        yield 'gemischte Tausendertrenner' => ['1.250,000,00'];
        yield 'zwei Vorzeichen' => ['--5'];

        // Ohne Grenze liefe die Umwandlung nach int still bei PHP_INT_MAX
        // an, die Multiplikation danach in Fliesskomma. Beides ergäbe einen
        // falschen Betrag statt einer Fehlermeldung.
        yield 'einen Cent über dem Wertebereich' => ['92233720368547758,08'];
        yield 'einen Cent unter dem Wertebereich' => ['-92233720368547758,09'];
        yield 'weit über dem Wertebereich' => ['999999999999999999999'];
        yield 'zu große Ziffernfolge mit Tausendertrennern' => ['999.999.999.999.999.999.999,99'];
    }

    public function testSaysWhenAnAmountIsMerelyOutOfRange(): void
    {
        $this->expectException(UnreadableAmount::class);
        $this->expectExceptionMessageIsOrContains('darstellbaren Bereichs');

        MoneyInput::parse('92233720368547758,08');
    }

    public function testKeepsTheRejectedInputInTheMessage(): void
    {
        $this->expectException(UnreadableAmount::class);
        $this->expectExceptionMessageIsOrContains('1,2345');

        MoneyInput::parse('1,2345');
    }
}
