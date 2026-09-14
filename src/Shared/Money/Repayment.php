<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Shared\Money;

/**
 * Ein Monat im Tilgungsplan.
 *
 * Die Rate zerfaellt in Zins und Tilgung, und das Verhaeltnis verschiebt sich
 * mit jeder Zahlung: am Anfang ist fast alles Zins, am Ende fast alles
 * Tilgung. Beides steht darum einzeln da — eine Rate ohne diese Teilung sagt
 * nicht, ob das Darlehen kleiner wird.
 */
final readonly class Repayment
{
    public function __construct(
        /** Der wievielte Monat, ab eins. */
        public int $month,
        public Money $payment,
        public Money $interest,
        public Money $principal,
        /** Was danach noch offen ist. */
        public Money $balance,
        /**
         * Was ausserplanmaessig getilgt wurde — meistens nichts.
         *
         * Sie steht neben der Rate und nicht darin: eine Sondertilgung ist
         * keine Rate, und wer die Belastung eines Jahres plant, plant die
         * Raten. Sie mindert die Restschuld trotzdem sofort, und genau
         * deshalb steht sie in derselben Zeile.
         */
        public ?Money $extra = null,
    ) {
    }

    /** Was ausserplanmaessig getilgt wurde — null heisst nichts. */
    public function extra(): Money
    {
        return $this->extra ?? Money::zero();
    }
}
