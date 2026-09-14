<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Finance\Domain;

use App\Shared\Money\Money;

/**
 * Wo die Kosten eines Wirtschaftsjahres liegen.
 *
 * Drei Sichten auf dieselbe Summe: das Ganze, die Aufteilung nach Kostenart
 * und die nach Objekt. Jede fuer sich beantwortet eine andere Frage — „was
 * kostet uns das Jahr", „wofuer geht es drauf", „welches Haus ist teuer".
 *
 * Die Teilsummen sind absteigend sortiert: eine Verteilung liest man von der
 * groessten Position nach unten, und was ganz unten steht, ist ohnehin
 * gleich.
 */
final readonly class CostBreakdown
{
    /**
     * @param list<array{label: string, amount: Money}> $byKind
     * @param list<array{label: string, amount: Money}> $byProperty
     */
    private function __construct(
        public int $fiscalYear,
        public Money $total,
        public Money $apportionable,
        public int $items,
        public array $byKind,
        public array $byProperty,
    ) {
    }

    /**
     * @param array<string, Money> $byKind     Bezeichnung auf Summe
     * @param array<string, Money> $byProperty Bezeichnung auf Summe
     */
    public static function of(
        int $fiscalYear,
        Money $total,
        Money $apportionable,
        int $items,
        array $byKind,
        array $byProperty,
    ): self {
        return new self(
            $fiscalYear,
            $total,
            $apportionable,
            $items,
            self::ranked($byKind),
            self::ranked($byProperty),
        );
    }

    public function isEmpty(): bool
    {
        return $this->total->isZero() && 0 === $this->items;
    }

    /** Was der Mieter nicht traegt. */
    public function ownCosts(): Money
    {
        return $this->total->minus($this->apportionable);
    }

    /**
     * Der umlagefaehige Anteil in Prozent, kaufmaennisch gerundet.
     *
     * Als ganze Prozent und nicht auf zwei Stellen: es ist eine Kennzahl auf
     * einer Uebersicht, keine Abrechnung. Wer die genaue Zahl braucht, findet
     * die Betraege daneben.
     */
    public function apportionableShare(): int
    {
        if (0 === $this->total->cents()) {
            return 0;
        }

        return (int) round($this->apportionable->cents() * 100 / $this->total->cents());
    }

    /**
     * Die groessten Posten, der Rest zu einem zusammengefasst.
     *
     * Eine Verteilung mit zwanzig Balken ist keine Verteilung mehr, sondern
     * eine Liste. Was unten steht, ist ohnehin gleich gross — es kommt darauf
     * an, dass die Summe stimmt, und die tut sie: der Rest steht als Rest da.
     *
     * @param list<array{label: string, amount: Money}> $rows
     *
     * @return list<array{label: string, amount: Money}>
     */
    public static function largest(array $rows, int $count, string $restLabel): array
    {
        if (\count($rows) <= $count) {
            return $rows;
        }

        $shown = \array_slice($rows, 0, $count - 1);
        $rest = Money::zero();

        foreach (\array_slice($rows, $count - 1) as $row) {
            $rest = $rest->plus($row['amount']);
        }

        $shown[] = ['label' => $restLabel, 'amount' => $rest];

        return $shown;
    }

    /**
     * @param array<string, Money> $sums
     *
     * @return list<array{label: string, amount: Money}>
     */
    private static function ranked(array $sums): array
    {
        uasort($sums, static fn (Money $one, Money $other): int => $other->cents() <=> $one->cents());

        $ranked = [];

        foreach ($sums as $label => $amount) {
            $ranked[] = ['label' => $label, 'amount' => $amount];
        }

        return $ranked;
    }
}
