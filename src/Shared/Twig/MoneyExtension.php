<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Shared\Twig;

use App\Shared\Locale\CurrentLocale;
use App\Shared\Money\Money;
use App\Shared\Money\MoneyFormatter;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;

/**
 * Stellt Geldbetraege in der Sprache der Anfrage dar.
 *
 * Die Sprache kommt aus der Anfrage, nicht aus einem Parameter am Aufrufort:
 * sonst muesste jede Vorlage sie durchreichen, und irgendeine vergisst es.
 */
final class MoneyExtension extends AbstractExtension
{
    public function __construct(private readonly CurrentLocale $locale)
    {
    }

    public function getFilters(): array
    {
        return [
            new TwigFilter('money', $this->money(...)),
            new TwigFilter('money_number', $this->moneyNumber(...)),
        ];
    }

    public function money(Money $money): string
    {
        return MoneyFormatter::format($money, $this->locale->code());
    }

    /**
     * Ohne Waehrungszeichen — fuer Eingabefelder, deren Inhalt wieder
     * eingelesen werden soll.
     */
    public function moneyNumber(Money $money): string
    {
        return MoneyFormatter::formatNumber($money, $this->locale->code());
    }
}
