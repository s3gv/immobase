<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Billing\Application;

use App\Module\Billing\Domain\AssetItem;
use App\Module\Billing\Domain\AssetKind;
use App\Module\Billing\Domain\AssetReport;
use App\Module\Billing\Domain\AssetReportRepository;
use App\Shared\Money\Money;

/**
 * Die erfassten Positionen eines Berichts pflegen.
 *
 * Jede Zeile wird ueber ihre eigene Kennung angesprochen und nicht ueber ihre
 * Stelle in der Liste: wer eine Zeile entfernt und dann speichert, verschoebe
 * sonst alle darunter.
 *
 * Die Art aendert sich nicht mehr, nachdem die Zeile angelegt ist. Aus einem
 * Guthaben wird keine Verbindlichkeit — wer sich vertan hat, loescht die
 * Zeile. Das ist ein Griff, und es erspart die Frage, was mit einer
 * Zweckbindung passiert, die es auf einer Verbindlichkeit nicht geben darf.
 */
final readonly class AssetItems
{
    public function __construct(private AssetReportRepository $reports)
    {
    }

    /**
     * Die eingegebenen Werte uebernehmen.
     *
     * Die Betraege kommen schon als {@see Money} herein — oder als null, wenn
     * das Feld leer blieb. Das Lesen einer getippten Zahl ist Sache der
     * Oberflaeche; sie ist es, die eine unlesbare Eingabe zurueckweisen und
     * dabei stehen lassen muss.
     *
     * @param array<string, array{label: string, amount: Money|null, earmarked: bool, note: string}> $rows Kennung der Zeile auf ihre Felder
     */
    public function keep(AssetReport $report, array $rows): void
    {
        foreach ($report->items() as $item) {
            $row = $rows[$item->id()] ?? null;

            if (null !== $row) {
                $item->describe($row['label'], $row['amount'], $row['earmarked'], $row['note']);
            }
        }

        $this->reports->save($report);
    }

    /** Eine leere Zeile der gewuenschten Art ans Ende. */
    public function add(AssetReport $report, AssetKind $kind): void
    {
        new AssetItem($report, self::nextOrdering($report), $kind);
        $this->reports->save($report);
    }

    public function remove(AssetReport $report, string $itemId): void
    {
        foreach ($report->items() as $item) {
            if ($item->id() === $itemId) {
                $report->drop($item);
            }
        }

        $this->reports->save($report);
    }

    private static function nextOrdering(AssetReport $report): int
    {
        $last = 0;

        foreach ($report->items() as $item) {
            $last = max($last, $item->ordering());
        }

        return $last + 1;
    }
}
