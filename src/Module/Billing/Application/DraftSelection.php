<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Billing\Application;

use App\Module\Billing\Domain\Statement;
use App\Module\Billing\Domain\StatementRepository;
use App\Module\Billing\Domain\StatementSource;
use App\Module\Finance\Contract\CostRecord;
use App\Module\Finance\Contract\PaymentRecord;

/**
 * Was der Mensch angehakt hat.
 *
 * Die Auswahl steht in denselben Zeilen, die die Quelle unloeschbar machen:
 * angehakt heisst eingegangen. Ein zweiter Ort dafuer waere ein zweiter
 * Stand, der auseinanderlaufen kann.
 *
 * Genau deshalb wird gespeichert, was auch gerechnet wird — und nichts
 * sonst. Eine Kennung, die der Lauf gar nicht anbietet, ignoriert die
 * Berechnung stillschweigend; als Quelle gespeichert wuerde sie den
 * Jahreswert eines fremden Objekts sperren, ohne je auf einem Schreiben zu
 * erscheinen. Ein Haken, den niemand setzen kann, darf nichts festhalten.
 */
final readonly class DraftSelection
{
    public function __construct(
        private StatementRepository $statements,
        private ComposeStatement $compose,
    ) {
    }

    /**
     * @return array{costs: list<string>, payments: list<string>}
     */
    public function of(Statement $statement): array
    {
        $costs = [];
        $payments = [];

        foreach ($this->statements->sourcesOf($statement->id()) as $source) {
            $costYear = $source->costYearId();
            $payment = $source->paymentId();

            if (null !== $costYear) {
                $costs[] = $costYear;
            }

            if (null !== $payment) {
                $payments[] = $payment;
            }
        }

        return ['costs' => $costs, 'payments' => $payments];
    }

    /**
     * @param list<string> $costs
     * @param list<string> $payments
     */
    public function keep(Statement $statement, array $costs, array $payments): void
    {
        $offered = $this->compose->offered($statement);
        $sources = [
            ...array_map(
                static fn (string $id): StatementSource => StatementSource::cost($statement->id(), $id),
                self::onlyOffered($costs, array_map(
                    static fn (CostRecord $cost): string => $cost->costYearId,
                    $offered['costs'],
                )),
            ),
            ...array_map(
                static fn (string $id): StatementSource => StatementSource::payment($statement->id(), $id),
                self::onlyOffered($payments, array_map(
                    static fn (PaymentRecord $payment): string => $payment->paymentId,
                    $offered['payments'],
                )),
            ),
        ];

        $this->statements->replaceSources($statement->id(), $sources);
    }

    /**
     * Was angehakt wurde, geschnitten auf das, was zur Wahl stand.
     *
     * Doppelte fallen dabei weg: zweimal dieselbe Kennung waere zweimal
     * dieselbe Zeile und liefe in den eindeutigen Index.
     *
     * @param list<string> $chosen
     * @param list<string> $offered
     *
     * @return list<string>
     */
    private static function onlyOffered(array $chosen, array $offered): array
    {
        return array_values(array_unique(array_intersect($chosen, $offered)));
    }
}
