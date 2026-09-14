<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Shared\Twig;

use App\Shared\Locale\CurrentLocale;
use App\Shared\Number\Decimals;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;

/**
 * Schreibt Dezimalzahlen in der Sprache der Anfrage.
 *
 * `{{ unit.area|decimal }}` wird zu „78,40" oder „78.40", je nach Sprache.
 * `|decimal(false)` laesst die Tausender ungetrennt — fuer Eingabefelder und
 * fuer Zahlen, die keine Menge sind.
 *
 * `{{ 1900|percent }}` wird zu „19,00 %" — Basispunkte, wie sie an der
 * Umsatzsteuer und am Darlehen stehen.
 */
final class NumberExtension extends AbstractExtension
{
    public function __construct(private readonly CurrentLocale $locale)
    {
    }

    public function getFilters(): array
    {
        return [
            new TwigFilter('decimal', $this->decimal(...)),
            new TwigFilter('percent', $this->percent(...)),
        ];
    }

    /**
     * Ein Satz in Basispunkten als Prozentzahl: 1900 wird zu „19,00 %".
     *
     * Basispunkte stehen an der Umsatzsteuer wie am Darlehen, und aus
     * demselben Grund: ein Prozentsatz als Dezimalzahl ist genau die Zahl,
     * die man nicht rechnen kann. Gelesen werden will er trotzdem als
     * Prozent, und die Umrechnung gehoert an eine Stelle — sonst teilt
     * irgendwo jemand ein zweites Mal durch hundert.
     *
     * `|percent(false)` laesst das Zeichen weg — fuer Eingabefelder, deren
     * Inhalt wieder eingelesen wird.
     */
    public function percent(int $basisPoints, bool $signed = true): string
    {
        // Das Minus muss vorne stehen und nicht aus `intdiv` kommen: −88
        // Basispunkte sind ganzzahlig null Prozent, und ohne das Vorzeichen
        // stuende „0,88 %" da, wo „−0,88 %" gilt. Genau dort lag der
        // Basiszinssatz von 2016 bis 2022.
        $minus = $basisPoints < 0 ? '-' : '';
        $points = abs($basisPoints);
        $number = $this->decimal($minus.\sprintf('%d.%02d', intdiv($points, 100), $points % 100), false);

        return $signed ? $number.' %' : $number;
    }

    public function decimal(string|int|null $value, bool $grouped = true): string
    {
        return null === $value ? '' : Decimals::format((string) $value, $this->locale->code(), $grouped);
    }
}
