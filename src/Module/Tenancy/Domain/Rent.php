<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Tenancy\Domain;

use App\Shared\Money\Money;
use InvalidArgumentException;

/**
 * Die vier Positionen einer Miete — und ihre Summe.
 *
 * Vier feste Felder und keine Liste beliebiger Posten. Das Vorgaengersystem
 * hatte dafuer `CostItem` mit fuenfzehn optionalen Fremdschluesseln und
 * Waechtern, die pruefen, dass sich nicht zwei davon widersprechen. Was eine
 * Wohnraummiete ausmacht, steht seit Jahrzehnten fest: Kaltmiete plus
 * Betriebskosten- und Heizkostenvorauszahlung ergibt die Warmmiete, ein
 * Stellplatz steht daneben, weil er anders umgelegt wird.
 *
 * Die Summe wird gerechnet und nirgends gespeichert: zwei Wahrheiten laufen
 * auseinander.
 *
 * Keine Position ist negativ. MoneyInput laesst ein Vorzeichen zu, und das
 * ist dort richtig — eine Buchung kann negativ sein. Eine Miete nicht: „minus
 * 800 Euro Kaltmiete" waere keine Gutschrift, sondern ein Vertipper, und er
 * wuerde jede Summe und jede Abrechnung darauf still verfaelschen. Die Regel
 * steht hier und nicht im Formular, damit sie fuer jeden Aufrufer gilt.
 */
final readonly class Rent
{
    private function __construct(
        public Money $base,
        public Money $operatingCosts,
        public Money $heating,
        public Money $parking,
    ) {
    }

    /**
     * @throws InvalidArgumentException
     */
    public static function of(Money $base, Money $operatingCosts, Money $heating, Money $parking): self
    {
        self::refuseNegative($base, 'Die Kaltmiete');
        self::refuseNegative($operatingCosts, 'Die Betriebskostenvorauszahlung');
        self::refuseNegative($heating, 'Die Heizkostenvorauszahlung');
        self::refuseNegative($parking, 'Die Stellplatzmiete');

        return new self($base, $operatingCosts, $heating, $parking);
    }

    public static function nothing(): self
    {
        return new self(Money::zero(), Money::zero(), Money::zero(), Money::zero());
    }

    /** Alles zusammen — das, was ueberwiesen wird. */
    public function total(): Money
    {
        return $this->base
            ->plus($this->operatingCosts)
            ->plus($this->heating)
            ->plus($this->parking);
    }

    /** Kaltmiete plus Vorauszahlungen, ohne Stellplatz. */
    public function warm(): Money
    {
        return $this->base->plus($this->operatingCosts)->plus($this->heating);
    }

    public function isZero(): bool
    {
        return $this->total()->isZero();
    }

    private static function refuseNegative(Money $amount, string $what): void
    {
        if ($amount->isNegative()) {
            throw new InvalidArgumentException($what.' kann nicht negativ sein.');
        }
    }
}
