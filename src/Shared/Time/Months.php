<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Shared\Time;

/**
 * Die zwoelf Monate als Auswahl.
 *
 * Aus der Sprachdatei und nicht aus PHPs Formatierung: die haengt an der
 * Locale des Servers, und die hat mit der Anzeigesprache nichts zu tun. Ein
 * Server auf „C" haette sonst englische Monatsnamen in einer deutschen
 * Oberflaeche.
 *
 * Zurueck kommt der Schluessel, nicht der Text — uebersetzt wird in der
 * Vorlage, wo die Sprache des Betrachters bekannt ist.
 */
final class Months
{
    private function __construct()
    {
    }

    /**
     * @return list<array{value: int, label: string}>
     */
    public static function forChoice(): array
    {
        return array_map(
            static fn (int $month): array => ['value' => $month, 'label' => 'month.'.$month],
            range(1, 12),
        );
    }
}
