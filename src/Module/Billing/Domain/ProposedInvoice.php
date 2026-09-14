<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Billing\Domain;

use App\Shared\Money\Money;

/**
 * Die Dauermietrechnung, wie sie auf dem Blatt steht.
 *
 * Dieselbe Gestalt fuer den Entwurf und fuer das ausgestellte Schreiben:
 * beim Entwurf eben gerechnet, beim ausgestellten aus dem gelesen, was
 * eingefroren wurde. Die Vorschau zeigt damit genau das, was hinausgeht —
 * und nicht eine zweite Rechnung, die ihr aehnelt.
 *
 * Die Summen stehen nicht als Feld, sondern werden gerechnet: eine
 * gespeicherte Bruttosumme neben ihren Teilen waere die zweite Wahrheit,
 * und sie faellt genau dann auseinander, wenn jemand nachrechnet.
 */
final readonly class ProposedInvoice
{
    public function __construct(
        public string $landlordName,
        public string $landlordAddress,
        public string $landlordTaxNumber,
        public string $tenantName,
        public string $tenantAddress,
        public string $letLabel,
        public Money $base,
        public Money $operatingCosts,
        public Money $heating,
        public Money $parking,
        public Taxation $taxation,
        public string $payeeName,
        public string $payeeIban,
        public Validity $validity,
        /** Was die E-Rechnung ueber das Blatt hinaus braucht. */
        public EInvoiceData $eInvoice,
    ) {
    }

    /** Was monatlich netto faellig wird. */
    public function net(): Money
    {
        return $this->base->plus($this->operatingCosts)->plus($this->heating)->plus($this->parking);
    }

    public function tax(): Money
    {
        return $this->taxation->on($this->net());
    }

    /** Was ueberwiesen wird. */
    public function gross(): Money
    {
        return $this->taxation->grossOf($this->net());
    }

    /**
     * Die Positionen, wie sie untereinander stehen — ohne die leeren.
     *
     * Eine Zeile „Stellplatz 0,00 €" behauptet einen Stellplatz, den es
     * nicht gibt. Die Kaltmiete steht immer da, auch bei null: eine Miete
     * ohne Kaltmiete ist eine Angabe, die auffallen soll.
     *
     * @return list<array{key: string, amount: Money}>
     */
    public function lines(): array
    {
        $lines = [['key' => 'base', 'amount' => $this->base]];

        foreach (['operating' => $this->operatingCosts, 'heating' => $this->heating, 'parking' => $this->parking] as $key => $amount) {
            if (!$amount->isZero()) {
                $lines[] = ['key' => $key, 'amount' => $amount];
            }
        }

        return $lines;
    }
}
