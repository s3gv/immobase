<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Billing\Application;

use App\Module\Billing\Domain\AssetItem;
use App\Module\Billing\Domain\AssetReport;

/**
 * Was einen Bericht davon abhaelt, herauszugehen.
 *
 * Zwei Luecken, und beide sind stille: eine Position ohne Bezeichnung ist
 * eine Zeile, die niemand lesen kann, und ein Konto ohne Stand ist eine Null,
 * die niemand eingegeben hat. Ein Gegenstand ohne Wert ist keine Luecke — er
 * ist der Normalfall und steht unbewertet da.
 */
final class AssetReportGaps
{
    private function __construct()
    {
    }

    /**
     * Die Positionen, an denen etwas fehlt.
     *
     * @return list<string> Kennungen — die Oberflaeche zeigt sie an der Zeile
     */
    public static function of(AssetReport $report): array
    {
        $missing = [];

        foreach ($report->items() as $item) {
            if (self::isIncomplete($item)) {
                $missing[] = $item->id();
            }
        }

        return $missing;
    }

    private static function isIncomplete(AssetItem $item): bool
    {
        if ('' === $item->label()) {
            return true;
        }

        return $item->kind()->needsAnAmount() && !$item->isValued();
    }
}
