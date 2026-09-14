<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Billing\Domain;

use App\Shared\Money\Money;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Die Umsatzsteuer, wie sie auf dem Schreiben steht — eingefroren.
 *
 * Dieselbe Gestalt wie die Vereinbarung am Mietverhaeltnis, aber eine eigene
 * Klasse: die Vereinbarung aendert sich, die ausgestellte Angabe nie. Wer
 * heute von 19 auf 7 Prozent wechselt, aendert damit keine Rechnung, die
 * gestern hinausging — er stellt eine neue aus.
 *
 * **Der Steuerbetrag wird aus der Nettosumme gerechnet, nicht je Position.**
 * Sonst summierten sich vier gerundete Betraege zu etwas anderem als der
 * Betrag unter dem Strich, und genau dort sieht der Mieter hin.
 *
 * Der Satz steht in Basispunkten: 19 % sind 1900.
 */
#[ORM\Embeddable]
final class Taxation
{
    #[ORM\Column(name: 'vat_charged', type: Types::BOOLEAN)]
    private bool $charged;

    #[ORM\Column(name: 'vat_rate_bps', type: Types::INTEGER)]
    private int $rateBps;

    private function __construct(bool $charged, int $rateBps)
    {
        $this->charged = $charged;
        $this->rateBps = $rateBps;
    }

    public static function exempt(): self
    {
        return new self(false, 0);
    }

    /** Ein Satz von null ist keine Option, sondern ein Ausweis ueber nichts. */
    public static function at(int $rateBps): self
    {
        return $rateBps > 0 ? new self(true, $rateBps) : self::exempt();
    }

    public function isCharged(): bool
    {
        return $this->charged;
    }

    public function rateBps(): int
    {
        return $this->rateBps;
    }

    /**
     * Was an Steuer auf diese Nettosumme entfaellt.
     *
     * `Netto × Satz ÷ 10.000`, alles in ganzen Zahlen, kaufmaennisch
     * gerundet — dieselbe Rechnung wie der Monatszins am Darlehen, und aus
     * demselben Grund: bei Geld rechnen wir nicht mit Fliesskomma.
     */
    public function on(Money $net): Money
    {
        if (!$this->charged) {
            return Money::zero();
        }

        return $net->basisPoints($this->rateBps);
    }

    /**
     * Die Steuer, die in einem Bruttobetrag steckt.
     *
     * `Brutto × Satz ÷ (10.000 + Satz)`, kaufmaennisch gerundet. Gebraucht
     * fuer die Vorauszahlungen einer Endrechnung: sie wurden brutto
     * gezahlt, und § 14 Abs. 5 UStG verlangt, dass die darauf entfallende
     * Steuer abgesetzt wird.
     */
    public function containedIn(Money $gross): Money
    {
        if (!$this->charged) {
            return Money::zero();
        }

        $divisor = 10000 + $this->rateBps;
        $sign = $gross->isNegative() ? -1 : 1;

        return Money::fromCents($sign * intdiv(2 * abs($gross->cents()) * $this->rateBps + $divisor, 2 * $divisor));
    }

    public function grossOf(Money $net): Money
    {
        return $net->plus($this->on($net));
    }
}
