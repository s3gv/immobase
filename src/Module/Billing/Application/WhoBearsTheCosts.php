<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Billing\Application;

use App\Module\Billing\Domain\Budget;
use App\Module\Billing\Domain\CostBearing;
use App\Module\Billing\Domain\MeasureKind;
use App\Module\Billing\Domain\Weight;
use App\Module\Property\Contract\UnitBrief;

/**
 * § 21 WEG, an einer Stelle.
 *
 * Bei der **Erhaltung** ist die Frage nicht gestellt: alle tragen. Bei einer
 * **baulichen Veraenderung** entscheidet, wie beschlossen wurde:
 *
 * * mit mehr als zwei Dritteln der abgegebenen Stimmen **und** der Haelfte
 *   aller Miteigentumsanteile — alle (Abs. 2 Nr. 1);
 * * oder die Kosten amortisieren sich in angemessener Zeit — ebenfalls alle
 *   (Abs. 2 Nr. 2);
 * * sonst nur die Zustimmenden, und nur sie duerfen nutzen (Abs. 3 und 4).
 *
 * **Solange nicht abgestimmt wurde, ist das Ergebnis eine Annahme.** Die
 * Beschlussvorlage kann nicht wissen, wie die Versammlung stimmt; sie sagt,
 * wovon die Verwaltung ausgeht, und die Oberflaeche sagt dazu, dass es eine
 * Annahme ist.
 *
 * Was hier **nicht** entschieden wird: ob die Kosten unverhaeltnismaessig sind.
 * § 21 Abs. 2 Nr. 1 nimmt solche Massnahmen aus, aber ob eine Summe
 * unverhaeltnismaessig ist, ist eine Wertung und keine Rechnung — sie bleibt
 * bei der Versammlung.
 */
final class WhoBearsTheCosts
{
    private function __construct()
    {
    }

    /**
     * @param list<UnitBrief> $units
     * @param list<string>    $approvals Kennungen der zustimmenden Einheiten
     */
    public static function of(Budget $budget, array $units, array $approvals): CostBearing
    {
        return match ($budget->measure()->kind()) {
            MeasureKind::Maintenance => CostBearing::Everyone,
            MeasureKind::Privileged => CostBearing::OnlyWhoAsked,
            MeasureKind::Structural => self::underSection21($budget, $units, $approvals),
        };
    }

    /**
     * Wer am Ende traegt — die Einheiten, auf die verteilt wird.
     *
     * Bei einer privilegierten Massnahme sind das die, die sie verlangt haben;
     * sie stehen in derselben Liste wie die Zustimmenden. Wer sie verlangt,
     * stimmt ihr zu.
     *
     * @param list<UnitBrief> $units
     * @param list<string>    $approvals
     *
     * @return list<UnitBrief>
     */
    public static function amongst(array $units, array $approvals, CostBearing $bearing): array
    {
        if ($bearing->isEveryone()) {
            return $units;
        }

        return array_values(array_filter(
            $units,
            static fn (UnitBrief $unit): bool => \in_array($unit->id, $approvals, true),
        ));
    }

    /**
     * @param list<UnitBrief> $units
     * @param list<string>    $approvals
     */
    private static function underSection21(Budget $budget, array $units, array $approvals): CostBearing
    {
        if (!$budget->verdict()->wasCounted()) {
            return self::assumed($budget);
        }

        if ($budget->verdict()->isQualified() && self::halfTheShares($units, $approvals)) {
            return CostBearing::QualifiedMajority;
        }

        if ($budget->measure()->amortisesReasonably()) {
            return CostBearing::Amortising;
        }

        return CostBearing::OnlyThoseWhoAgreed;
    }

    /** Wovon die Vorlage ausgeht, solange niemand abgestimmt hat. */
    private static function assumed(Budget $budget): CostBearing
    {
        return $budget->measure()->amortisesReasonably()
            ? CostBearing::Amortising
            : CostBearing::QualifiedMajority;
    }

    /**
     * Die Haelfte aller Miteigentumsanteile — § 21 Abs. 2 Nr. 1 WEG.
     *
     * Gerechnet wird ueber {@see Weight} und damit in ganzen Zahlen: ein
     * Miteigentumsanteil kommt als Dezimalzeichenkette, und „die Haelfte" darf
     * nicht an einer Fliesskommastelle haengen.
     *
     * @param list<UnitBrief> $units
     * @param list<string>    $approvals
     */
    private static function halfTheShares(array $units, array $approvals): bool
    {
        $all = 0;
        $agreed = 0;

        foreach ($units as $unit) {
            $weight = Weight::of($unit->mea);
            $all += $weight;

            if (\in_array($unit->id, $approvals, true)) {
                $agreed += $weight;
            }
        }

        return $all > 0 && $agreed * 2 >= $all;
    }
}
