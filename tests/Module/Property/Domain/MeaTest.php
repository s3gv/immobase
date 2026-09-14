<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Tests\Module\Property\Domain;

use App\Module\Property\Domain\Mea;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * Der Miteigentumsanteil — und warum er nicht mit Fliesskomma rechnet.
 *
 * Die Frage, auf die am Ende alles hinauslaeuft, lautet „ergeben alle Anteile
 * zusammen genau den Nenner". Bei vierzig kleinen Zahlen beantwortet
 * Fliesskomma sie falsch, und zwar leise.
 */
final class MeaTest extends TestCase
{
    public function testAddsExactlyAndDoesNotRoundUp(): void
    {
        $third = Mea::of('333.33', 1000);
        $sum = $third->plus($third)->plus($third);

        self::assertSame('999.99/1000', $sum->toString());
        self::assertFalse($sum->equals(Mea::whole(1000)), 'Drei Drittel sind hier eben nicht eins');
    }

    /**
     * Der Fall, den Fliesskomma nicht schafft: 0,1 ist als Fliesskomma nicht
     * 0,1, und zehn davon sind nicht eins.
     */
    public function testTenTenthsAreExactlyOne(): void
    {
        $sum = Mea::none(1000);

        for ($i = 0; $i < 10; ++$i) {
            $sum = $sum->plus(Mea::of('100', 1000));
        }

        self::assertTrue($sum->equals(Mea::whole(1000)));
    }

    public function testAcceptsBothDecimalMarks(): void
    {
        self::assertSame('225.5/10000', Mea::of('225,5', 10000)->toString());
        self::assertSame('225.5/10000', Mea::of('225.5', 10000)->toString());
    }

    public function testDropsTheDecimalPointWhenThereIsNothingBehindIt(): void
    {
        self::assertSame('54', Mea::of('54.00', 1000)->numerator());
        self::assertSame('54', Mea::of(54, 1000)->numerator());

        // Mit Nachkommastelle: die überflüssige Null fällt weg, die
        // gebrauchte bleibt — und gerechnet wird dabei nicht mit Fließkomma.
        self::assertSame('225.5', Mea::of('225,5', 10000)->numerator());
        self::assertSame('0.05', Mea::of('0,05', 1000)->numerator());
    }

    public function testRefusesMoreThanTwoDecimals(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Mea::of('12,3456', 1000);
    }

    /**
     * „12.345" sind zwoelftausenddreihundertfuenfundvierzig und nicht zwoelf
     * Komma drei vier fuenf: genau drei Ziffern hinter einem einzelnen
     * Trennzeichen sind eine Tausendergruppe. Dieselbe Regel gilt fuer
     * Geldbetraege — siehe Decimals::roles().
     */
    public function testReadsThreeDigitsBehindASingleMarkAsThousands(): void
    {
        self::assertSame('12345/100000', Mea::of('12.345', 100000)->toString());
        self::assertSame('12345/100000', Mea::of('12,345', 100000)->toString());
    }

    public function testRefusesSomethingThatIsNotANumber(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Mea::of('viel', 1000);
    }

    public function testRefusesANegativeShare(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Mea::of('-5', 1000);
    }

    public function testRefusesADenominatorOfZero(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Mea::of('1', 0);
    }

    /**
     * Anteile auf verschiedenen Skalen zu verrechnen ergaebe eine Zahl, die
     * aussieht wie ein Ergebnis und keines ist.
     */
    public function testRefusesToMixScales(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Mea::of('50', 1000)->plus(Mea::of('50', 10000));
    }

    public function testSubtractingNeverGoesBelowZero(): void
    {
        self::assertTrue(Mea::of('10', 1000)->minus(Mea::of('30', 1000))->isZero());
    }

    public function testComparesOnTheSameScale(): void
    {
        self::assertTrue(Mea::of('49.99', 1000)->isLessThan(Mea::of('50', 1000)));
        self::assertFalse(Mea::of('50', 1000)->isLessThan(Mea::of('50', 1000)));
    }
}
