<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Finance\Contract;

use App\Shared\Money\Money;
use DateTimeImmutable;

/**
 * Eine beschlossene Sonderumlage, so wie sie die Finanzen entgegennehmen.
 *
 * Bewusst flach: wer eine Sonderumlage beschliesst, kennt Einheit, Betrag und
 * Faelligkeit. Eine Rate ist eine eigene Zeile — der Beschluss nennt jede
 * Faelligkeit, und zwei Raten sind zwei Termine und nicht ein halber.
 *
 * Die Referenz kommt mit. Sie ist die Antwort auf die Frage, die zu einer
 * Sonderumlage immer als erste gestellt wird: wofuer.
 */
final readonly class PlannedLevy
{
    public function __construct(
        public string $unitId,
        public DateTimeImmutable $dueOn,
        public Money $amount,
        /** Die Referenz des Budgetplans, zum Beispiel `BU-20001/1-2027-3-1`. */
        public string $reference,
        /**
         * Fliesst sie der Erhaltungsruecklage zu?
         *
         * Der Beschluss sagt es, und die Finanzen merken es sich an der
         * Zahlung: sie wird sonst in der Jahresabrechnung einem Kostenanteil
         * gegenuebergestellt, dem sie nicht gehoert.
         */
        public bool $forTheReserve = false,
    ) {
    }
}
