<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Finance\Contract;

use DateTimeImmutable;

/**
 * Eine Sonderumlage, die eine berichtigte Fassung nicht mehr vorsieht.
 *
 * Einheit, Tag und Beschluss — der Betrag waere hier keine Auskunft, sondern
 * eine Behauptung ueber das, was einmal galt.
 *
 * Ohne sie bliebe die alte Rate stehen. Wer drei Vierteljahresraten zu acht
 * Monatsraten berichtigt, schuldete danach beides: die Berichtigung ersetzt
 * den Beschluss und nicht nur das Blatt.
 */
final readonly class WithdrawnLevy
{
    public function __construct(
        public string $unitId,
        public DateTimeImmutable $dueOn,
        /**
         * Aus welchem Beschluss sie stammte.
         *
         * Ohne sie naehme eine Berichtigung die Sonderumlage einer anderen
         * Massnahme mit, die zufaellig am selben Tag faellig ist.
         */
        public string $reference,
    ) {
    }
}
