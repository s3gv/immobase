<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Shared\Money;

use App\Shared\Number\Scaled;

/**
 * Geldbetrag in ganzen Cent.
 *
 * Es gibt bewusst keine Konstruktion aus Float. Fliesskomma kann Betraege wie
 * 0,10 Euro nicht exakt darstellen; in einer Abrechnung summieren sich solche
 * Fehler zu Betraegen, die nicht aufgehen.
 */
final readonly class Money
{
    /** Cent sind Hundertstel. */
    private const int DECIMALS = 2;

    private function __construct(private int $cents)
    {
    }

    public static function fromCents(int $cents): self
    {
        return new self($cents);
    }

    public static function zero(): self
    {
        return new self(0);
    }

    public function cents(): int
    {
        return $this->cents;
    }

    public function plus(self $other): self
    {
        return new self($this->cents + $other->cents);
    }

    public function minus(self $other): self
    {
        return new self($this->cents - $other->cents);
    }

    public function multipliedBy(int $factor): self
    {
        if ($factor < 0) {
            throw MoneyMismatch::negativeFactor($factor);
        }

        return new self($this->cents * $factor);
    }

    /**
     * Ein Anteil in Basispunkten, kaufmaennisch gerundet — 1900 sind 19 %.
     *
     * Ganzzahlig und ohne Fliesskomma: `Betrag × Punkte ÷ 10.000`, die Haelfte
     * aufgerundet. Fuer negative Betraege spiegelbildlich, damit eine
     * Gutschrift dieselbe Steuer traegt wie die Forderung.
     */
    public function basisPoints(int $points): self
    {
        $sign = $this->cents < 0 ? -1 : 1;

        return new self($sign * intdiv(abs($this->cents) * $points + 5000, 10000));
    }

    public function equals(self $other): bool
    {
        return $this->cents === $other->cents;
    }

    /**
     * Teilt den Betrag im Verhaeltnis der uebergebenen Gewichte auf.
     *
     * Die Summe der Teile entspricht immer exakt dem Ausgangsbetrag. Jeder Teil
     * bekommt zunaechst seinen abgerundeten Anteil; die verbleibenden Cent werden
     * der Reihe nach von vorne verteilt. Damit geht kein Cent verloren und es
     * entsteht keiner.
     *
     * @param list<int> $ratios
     *
     * @return list<self>
     */
    public function allocate(array $ratios): array
    {
        $total = self::assertUsableRatios($ratios);

        $sign = $this->cents < 0 ? -1 : 1;
        $shares = self::distribute(abs($this->cents), $ratios, $total);

        return array_values(array_map(
            static fn (int $share): self => new self($sign * $share),
            $shares,
        ));
    }

    /**
     * Der Betrag als Dezimalzahl — „1240.50", maschinenlesbar.
     *
     * Nicht fuer die Anzeige: dafuer gibt es {@see MoneyFormatter}, der die
     * Sprache kennt. Das hier ist die Form, in der eine Zahl weiterverrechnet
     * oder als Verteilerschluessel benutzt wird.
     *
     * Ueber {@see Scaled::asDecimal()} und nicht ueber `/ 100`: eine
     * Division ergibt Fliesskomma, und ab etwa neun Billiarden Cent kommt
     * dabei ein anderer Betrag heraus als der, der hineinging.
     */
    public function toDecimal(): string
    {
        return Scaled::asDecimal($this->cents, self::DECIMALS);
    }

    /**
     * Ein Teil von `$parts`, auf den vollen Cent aufgerundet.
     *
     * Fuer wiederkehrende Zahlungen: ein Vorschuss ist jeden Monat derselbe
     * Betrag, und ein Jahresanteil geht selten durch zwoelf. {@see allocate()}
     * loest das anders und richtig — dort sind die Teile verschieden gross,
     * und die Summe stimmt exakt. Ein Dauerauftrag kann das nicht.
     *
     * Aufgerundet und nicht kaufmaennisch gerundet: die Gemeinschaft soll
     * nicht zu wenig einnehmen. Die Differenz von hoechstens einem Cent je
     * Zahlung gleicht die Jahresabrechnung aus — dafuer ist sie da.
     *
     * @throws MoneyMismatch
     */
    public function eachOf(int $parts): self
    {
        if ($parts < 1) {
            throw MoneyMismatch::tooFewParts($parts);
        }

        $sign = $this->cents < 0 ? -1 : 1;
        $whole = abs($this->cents);
        $each = intdiv($whole, $parts) + (0 === $whole % $parts ? 0 : 1);

        return new self($sign * $each);
    }

    public function isZero(): bool
    {
        return 0 === $this->cents;
    }

    public function isNegative(): bool
    {
        return $this->cents < 0;
    }

    /**
     * Prueft die Gewichte und liefert ihre Summe.
     *
     * @param list<int> $ratios
     */
    private static function assertUsableRatios(array $ratios): int
    {
        if ([] === $ratios) {
            throw MoneyMismatch::emptyRatios();
        }

        foreach ($ratios as $ratio) {
            if ($ratio < 0) {
                throw MoneyMismatch::negativeRatio($ratio);
            }
        }

        $total = array_sum($ratios);

        if ($total <= 0) {
            throw MoneyMismatch::nonPositiveRatioSum($total);
        }

        return $total;
    }

    /**
     * Verteilt einen positiven Betrag; die Restcent gehen der Reihe nach an die vorderen Teile.
     *
     * @param list<int> $ratios
     *
     * @return list<int>
     */
    private static function distribute(int $amount, array $ratios, int $total): array
    {
        $shares = [];
        $distributed = 0;

        foreach ($ratios as $ratio) {
            $share = intdiv($amount * $ratio, $total);
            $shares[] = $share;
            $distributed += $share;
        }

        $remainder = $amount - $distributed;

        // Ueber die vorhandenen Schluessel laufen, nicht ueber einen Zaehler:
        // so ist belegt, dass jeder Zugriff einen existierenden Index trifft.
        foreach (array_keys($shares) as $position) {
            if ($remainder <= 0) {
                break;
            }

            ++$shares[$position];
            --$remainder;
        }

        return $shares;
    }
}
