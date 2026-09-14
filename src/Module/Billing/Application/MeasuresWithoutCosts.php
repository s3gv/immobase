<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Billing\Application;

use App\Module\Finance\Contract\CostDirectory;
use App\Module\Finance\Contract\PaymentRecord;

/**
 * Sonderumlagen, denen im Abrechnungsjahr keine Rechnung gegenuebersteht.
 *
 * Eine Sonderumlage **fuer die Massnahme** ist ein Vorschuss auf deren
 * Kosten. Stehen die Kosten in einem anderen Jahr — die Umlage wird 2026
 * eingesammelt, gebaut wird 2028 —, dann entsteht in der Abrechnung ein
 * Guthaben, das keines ist, und zwei Jahre spaeter eine Nachzahlung, die
 * niemand erwartet.
 *
 * Das ist **kein Fehler**, sondern eine Lage, die vorkommt. Darum ein Hinweis
 * und keine Sperre: wer die Umlage trotzdem aufnimmt, hat vermutlich einen
 * Grund — und wer sie herausnimmt, soll wissen, warum sie zur Wahl stand.
 *
 * Gefragt wird ueber die Nummer des Beschlusses, die an beiden Enden steht:
 * an der Zahlung, die hereinkam, und an der Kostenposition, die bezahlt
 * wurde. Ohne zugeordnete Kostenposition weiss niemand etwas — dann ist die
 * Antwort „keine Kosten gefunden", und genau das ist der Hinweis.
 */
final readonly class MeasuresWithoutCosts
{
    public function __construct(private CostDirectory $costs)
    {
    }

    /**
     * @param list<PaymentRecord> $payments
     *
     * @return list<string> die Nummern der Beschluesse ohne Kosten im Jahr
     */
    public function among(array $payments, int $fiscalYear): array
    {
        $missing = [];

        foreach ($payments as $payment) {
            if ('' !== $payment->reference && !isset($missing[$payment->reference])) {
                $missing[$payment->reference] = !$this->anyCostIn($payment->reference, $fiscalYear);
            }
        }

        return array_keys(array_filter($missing));
    }

    private function anyCostIn(string $reference, int $fiscalYear): bool
    {
        foreach ($this->costs->forMeasure($reference) as $cost) {
            if ($cost->fiscalYear === $fiscalYear) {
                return true;
            }
        }

        return false;
    }
}
