<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Finance\Application;

use App\Module\Finance\Contract\AdvanceDirectory;
use App\Module\Finance\Contract\PaymentRecord;
use App\Module\Finance\Domain\AdvancePayment;
use App\Module\Finance\Domain\AdvancePaymentRepository;
use DateTimeImmutable;

/**
 * Die Zahlungen eines Objektjahres, flach fuer die Abrechnung.
 *
 * **Ohne die Sonderumlagen, die der Ruecklage zufliessen.** Sie sind kein
 * Vorschuss auf die Kosten des Jahres: das Geld liegt auf der Ruecklage, bis
 * eine Rechnung daraus bezahlt wird. Stuende es in der Ergebnisrechnung,
 * entstuende ein Guthaben, dem nichts gegenuebersteht — und im Jahr der
 * Entnahme eine Nachzahlung, die niemand erwartet.
 *
 * Verschwunden ist es damit nicht: es steht im Ruecklagenauszug der
 * Hausgeldabrechnung, und was davon offen blieb, als Forderung im
 * Vermoegensbericht.
 */
final readonly class SurveyPaymentsForBilling implements AdvanceDirectory
{
    public function __construct(private AdvancePaymentRepository $payments)
    {
    }

    public function paymentsFor(array $unitIds, int $fiscalYear): array
    {
        $owed = array_filter(
            $this->payments->forYear($unitIds, $fiscalYear),
            static fn (AdvancePayment $payment): bool => !$payment->kind()->feedsTheReserve(),
        );

        return array_map(self::record(...), array_values($owed));
    }

    public function overdueOn(DateTimeImmutable $day): array
    {
        // Was gar nicht kam **und** was zu wenig kam. Eine Teilzahlung ist
        // kein erledigter Vorgang: ueber den Rest wird gemahnt.
        //
        // Der Faelligkeitstag selbst gehoert nicht dazu: an ihm laeuft die
        // Frist noch, und Verzug beginnt erst am Tag danach.
        $open = array_filter(
            $this->payments->unsettledBefore($day),
            static fn (AdvancePayment $payment): bool => $payment->expected()->cents() > $payment->received()->cents(),
        );

        return array_map(self::record(...), array_values($open));
    }

    private static function record(AdvancePayment $payment): PaymentRecord
    {
        return new PaymentRecord(
            paymentId: $payment->id(),
            unitId: $payment->unitId(),
            kind: $payment->kind()->value,
            owedByTheOwner: $payment->kind()->isOwedByTheOwner(),
            dueOn: $payment->dueOn(),
            expected: $payment->expected(),
            received: $payment->received(),
            reference: $payment->reference(),
        );
    }
}
