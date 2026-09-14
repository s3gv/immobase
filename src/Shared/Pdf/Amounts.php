<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Shared\Pdf;

use App\Shared\Locale\CurrentLocale;
use App\Shared\Money\Money;
use App\Shared\Money\MoneyFormatter;
use App\Shared\Number\Decimals;

/**
 * Zahlen auf einem Blatt, in der Schreibweise der Sprache.
 *
 * Gespeichert steht ein Anteil als `78.40` da — maschinenlesbar und mit Punkt.
 * So gedruckt liest ein deutscher Empfaenger `7840`, und die Zeile daneben
 * nennt im selben Atemzug `1.400,50 €`. Zwei Schreibweisen auf einem Blatt
 * sind schlimmer als eine ungewohnte.
 *
 * An einer Stelle fuer alle Schreiben: eine Abrechnung, ein Wirtschaftsplan und
 * eine Mahnung derselben Verwaltung duerfen nicht verschieden aussehen.
 *
 * Liegt in Shared und nicht in einem Modul, weil mehr als ein Modul Briefe
 * schreibt. Was ein einzelnes Modul darueber hinaus formulieren muss — etwa
 * einen Verteilerschluessel in Worten — formuliert es bei sich.
 */
final readonly class Amounts
{
    public function __construct(private CurrentLocale $locale)
    {
    }

    public function money(Money $money): string
    {
        return MoneyFormatter::format($money, $this->locale->code());
    }

    public function number(string $value): string
    {
        return Decimals::format($value, $this->locale->code());
    }
}
