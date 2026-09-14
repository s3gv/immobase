<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Property\Domain;

use App\Shared\Number\Decimals;
use App\Shared\Number\Scaled;
use InvalidArgumentException;

/**
 * Ein Miteigentumsanteil als Bruch — „54/1000", „225,5/10000".
 *
 * Der Zaehler darf zwei Nachkommastellen haben; Teilungserklaerungen nutzen
 * sie, wenn eine Einheit geteilt wurde. Gerechnet wird deshalb in Hundertsteln
 * als Ganzzahl und nie mit Fliesskomma: bei vierzig kleinen Anteilen sammelt
 * Fliesskomma genau die Rundungsfehler ein, auf die die Frage „ergibt die
 * Summe den ganzen Nenner" hinauslaeuft.
 *
 * Aus dem Vorgaengersystem uebernommen, weil es dort aus gutem Grund so steht.
 */
final readonly class Mea
{
    /** Hundertstel je Zaehlereinheit — zwei Nachkommastellen. */
    /** Der Zaehler darf zwei Nachkommastellen haben — „225,5/1000". */
    private const int DECIMALS = 2;
    private const int SCALE = 100;

    /** Der Zaehler in Hundertsteln: „225,5" liegt hier als 22550. */
    private int $scaled;

    private function __construct(int $scaled, public int $denominator)
    {
        if ($denominator <= 0) {
            throw new InvalidArgumentException('Der Nenner muss größer als null sein.');
        }

        if ($scaled < 0) {
            throw new InvalidArgumentException('Ein Anteil kann nicht negativ sein.');
        }

        $this->scaled = $scaled;
    }

    /**
     * Der Zaehler kommt aus einem Formular: „54", „225,5" — oder Unsinn. Die
     * Pruefung steht deshalb in scale() und nicht im Typ.
     */
    public static function of(string|int $numerator, int $denominator): self
    {
        return new self(self::scale((string) $numerator), $denominator);
    }

    public static function none(int $denominator): self
    {
        return new self(0, $denominator);
    }

    /** Der ganze Nenner — der Anteil, den alle Einheiten zusammen ergeben. */
    /**
     * Was mehrere Eigentuemer zusammen halten.
     *
     * Der Nenner kommt von aussen und nicht aus dem ersten Anteil: bei einer
     * leeren Liste gaebe es keinen ersten, und „null von nichts" ist keine
     * Aussage.
     *
     * @param list<UnitOwner> $owners
     */
    public static function sum(array $owners, int $denominator): self
    {
        $sum = self::none($denominator);

        foreach ($owners as $owner) {
            $sum = $sum->plus($owner->mea());
        }

        return $sum;
    }

    public static function whole(int $denominator): self
    {
        return new self($denominator * self::SCALE, $denominator);
    }

    public function plus(self $other): self
    {
        $this->refuseOtherScale($other);

        return new self($this->scaled + $other->scaled, $this->denominator);
    }

    public function minus(self $other): self
    {
        $this->refuseOtherScale($other);

        return new self(max(0, $this->scaled - $other->scaled), $this->denominator);
    }

    public function equals(self $other): bool
    {
        return $this->denominator === $other->denominator && $this->scaled === $other->scaled;
    }

    public function isLessThan(self $other): bool
    {
        $this->refuseOtherScale($other);

        return $this->scaled < $other->scaled;
    }

    public function isZero(): bool
    {
        return 0 === $this->scaled;
    }

    /** Der Zaehler, wie er in einem Feld steht: „54" oder „225,5". */
    public function numerator(): string
    {
        if (0 === $this->scaled % self::SCALE) {
            return (string) intdiv($this->scaled, self::SCALE);
        }

        return rtrim(Scaled::asDecimal($this->scaled, self::DECIMALS), '0');
    }

    public function toString(): string
    {
        return $this->numerator().'/'.$this->denominator;
    }

    /**
     * Wie viel Hundertstel steckt in der Eingabe?
     *
     * Ueber die Zeichenkette und nicht ueber (float): „0.1" ist als
     * Fliesskomma nicht 0,1, und drei davon sind nicht 0,3.
     */
    private static function scale(string $numerator): int
    {
        $clean = Decimals::normalise($numerator);

        if (1 !== preg_match('/^\d+(\.\d{1,2})?$/D', $clean)) {
            throw new InvalidArgumentException(\sprintf('Ein Zähler ist eine Zahl mit höchstens zwei Nachkommastellen, „%s" ist keine.', $numerator));
        }

        $parts = explode('.', $clean, 2);

        return (int) $parts[0] * self::SCALE + (int) str_pad($parts[1] ?? '0', 2, '0');
    }

    private function refuseOtherScale(self $other): void
    {
        if ($this->denominator !== $other->denominator) {
            throw new InvalidArgumentException('Anteile mit verschiedenen Nennern lassen sich nicht verrechnen.');
        }
    }
}
