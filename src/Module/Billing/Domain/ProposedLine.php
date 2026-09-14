<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Billing\Domain;

use App\Shared\Money\Money;

/**
 * Eine gerechnete Zeile, noch nicht eingefroren.
 *
 * Dieselben Angaben wie {@see StatementLine} — die Vorschau zeigt sie, und
 * die Freigabe macht daraus die Zeile. Was geprueft wurde, ist was
 * gespeichert wird.
 */
final readonly class ProposedLine
{
    public function __construct(
        public string $costKind,
        public Distribution $distribution,
        public Money $total,
        public Money $amount,
        /** Die Umsatzsteuer im Gesamtbetrag — null, wo keine ausgewiesen war. */
        public Money $totalInputTax,
        /** Ihr Anteil daran, mit denselben Gewichten verteilt wie der Betrag. */
        public Money $inputTax,
    ) {
    }

    /**
     * Der Anteil ohne die enthaltene Umsatzsteuer.
     *
     * Nie weniger als null: bei fertig verteilten Kosten steht der Betrag erst
     * mit den Einzelwerten fest, und eine Zeile, die mehr Steuer abzieht, als
     * sie kostet, waere eine Gutschrift aus dem Nichts.
     */
    public function net(): Money
    {
        return $this->amount->cents() > $this->inputTax->cents()
            ? $this->amount->minus($this->inputTax)
            : Money::zero();
    }

    public function totalNet(): Money
    {
        return $this->total->cents() > $this->totalInputTax->cents()
            ? $this->total->minus($this->totalInputTax)
            : Money::zero();
    }
}
