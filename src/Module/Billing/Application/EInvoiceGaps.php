<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Billing\Application;

use App\Module\Billing\Domain\EInvoiceData;

/**
 * Was einer Rechnung mit Umsatzsteuer fuer ihre E-Rechnung noch fehlt.
 *
 * Benannt und nicht gezaehlt, wie {@see RentInvoiceGaps}. Ab 2028 ist eine
 * Rechnung mit Umsatzsteuer zwischen Unternehmen ohne E-Rechnung keine — sie
 * haelt deshalb genauso auf wie eine fehlende Steuernummer. Steuerfreie
 * Belege fragen gar nicht erst.
 *
 * Geprueft wird, was die XRechnung verlangt und nicht schon woanders
 * geprueft ist: die Anschriften in Teilen (BR-08, BR-10), Referenz und
 * Adresse des Kaeufers (BR-DE-15, BT-49), der Ansprechpartner (BR-DE-2,
 * BR-DE-5, BR-DE-6, BR-DE-7) und bei Lastschrift Mandat, Glaeubiger-ID und
 * IBAN des Zahlenden (BR-DE-24, BR-DE-25).
 */
final class EInvoiceGaps
{
    private function __construct()
    {
    }

    /**
     * @return list<string> Uebersetzungsschluessel
     */
    public static function of(EInvoiceData $data): array
    {
        $missing = [
            ...self::aboutTheParties($data),
            ...self::aboutTheContact($data),
        ];

        if ($data->isDirectDebit()) {
            $debit = $data->directDebit();

            foreach (['mandate' => 'sepa_mandate', 'debtorIban' => 'debtor_iban', 'creditorId' => 'creditor_id'] as $field => $key) {
                if ('' === $debit[$field]) {
                    $missing[] = 'billing.einvoice.missing.'.$key;
                }
            }
        }

        return $missing;
    }

    /**
     * @return list<string>
     */
    private static function aboutTheParties(EInvoiceData $data): array
    {
        $missing = [];

        if (\in_array('', $data->seller(), true)) {
            $missing[] = 'billing.einvoice.missing.seller_address';
        }

        if (\in_array('', $data->buyer(), true)) {
            $missing[] = 'billing.einvoice.missing.buyer_address';
        }

        if ('' === $data->buyerReference()) {
            $missing[] = 'billing.einvoice.missing.buyer_reference';
        }

        if ('' === $data->buyerEAddress()) {
            $missing[] = 'billing.einvoice.missing.buyer_e_address';
        }

        return $missing;
    }

    /**
     * @return list<string>
     */
    private static function aboutTheContact(EInvoiceData $data): array
    {
        $missing = [];

        foreach ($data->contact() as $key => $value) {
            if ('' === $value) {
                $missing[] = 'billing.einvoice.missing.contact_'.$key;
            }
        }

        return $missing;
    }
}
