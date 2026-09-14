<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Billing\Application;

use App\Module\Billing\Domain\AssetItem;
use App\Module\Billing\Domain\AssetKind;
use App\Module\Billing\Domain\AssetReport;
use App\Module\Billing\Domain\AssetReportRepository;

/**
 * Den Bericht mit dem vorbelegen, was im Vorjahr daranstand.
 *
 * **Bezeichnungen ja, Betraege nein.** Die Konten heissen dieses Jahr wie im
 * letzten, und sie abzutippen ist die Arbeit, die niemand machen will. Ihr
 * Stand dagegen ist ein anderer — ein vorbelegter Kontostand von vor einem
 * Jahr saehe aus wie eingegeben und waere die gefaehrlichste Zahl im ganzen
 * Bericht. Was fehlt, haelt die Herausgabe auf; was falsch vorbelegt ist,
 * haelt niemanden auf.
 *
 * Gibt es keinen Vorjahresbericht, steht eine leere Kontozeile da. Ein ganz
 * leerer Schritt saehe aus, als koenne man dort nichts eintragen.
 */
final readonly class AssetsFromLastYear
{
    public function __construct(private AssetReportRepository $reports)
    {
    }

    public function fill(AssetReport $report): void
    {
        $before = $this->reports->lastReleasedBefore($report->propertyId(), $report->period()->year());

        if (null === $before) {
            new AssetItem($report, 1, AssetKind::Bank);

            return;
        }

        foreach ($before->items() as $item) {
            $item->carryInto($report);
        }
    }
}
