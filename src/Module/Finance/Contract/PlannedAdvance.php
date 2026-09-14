<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Finance\Contract;

use App\Shared\Money\Money;
use DateTimeImmutable;

/**
 * Ein beschlossener Vorschuss, so wie ihn die Finanzen entgegennehmen.
 *
 * Bewusst flach: wer einen Vorschuss beschliesst, kennt Einheit, Betrag,
 * Intervall und den Tag, ab dem er gilt — nicht die Hausgeldstufe mit ihren
 * Regeln.
 *
 * Die Referenz kommt mit und wird an der Stufe festgeschrieben. Sie ist die
 * Antwort auf die Frage, die zu einem Vorschuss immer als erste gestellt wird:
 * wer hat das beschlossen.
 */
final readonly class PlannedAdvance
{
    public function __construct(
        public string $unitId,
        /** Der erste Faelligkeitstag — ab ihm gilt der Betrag. */
        public DateTimeImmutable $startsOn,
        public Money $amount,
        public Interval $interval,
        /** Die Referenz des Wirtschaftsplans, zum Beispiel `WP-20001/1-2027-3-1`. */
        public string $reference,
    ) {
    }
}
