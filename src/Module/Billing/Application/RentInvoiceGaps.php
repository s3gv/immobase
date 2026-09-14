<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Billing\Application;

use App\Module\Billing\Domain\ProposedInvoice;
use App\Module\Billing\Domain\RentInvoice;
use App\Module\Tenancy\Contract\TenancyBrief;

/**
 * Was einer Dauermietrechnung noch fehlt.
 *
 * Benannt und nicht gezaehlt: „drei Angaben fehlen" schickt jemanden suchen.
 * Eine Rechnung, der eine Pflichtangabe des § 14 Abs. 4 UStG fehlt, ist
 * keine — der Mieter zieht daraus keine Vorsteuer, und er merkt es erst,
 * wenn sein Finanzamt es ihm sagt.
 *
 * **Die Steuernummer haelt nur auf, wenn Umsatzsteuer ausgewiesen wird.** Bei
 * steuerfreier Wohnraumvermietung besteht ueberhaupt keine Rechnungspflicht;
 * das Schreiben ist dort ein freiwilliger Beleg, und eine fehlende Nummer
 * macht es nicht wertlos.
 */
final readonly class RentInvoiceGaps
{
    private function __construct()
    {
    }

    /**
     * @return list<string> Uebersetzungsschluessel, in der Reihenfolge des Blattes
     */
    public static function of(RentInvoice $invoice, ProposedInvoice $proposal, ?TenancyBrief $tenancy): array
    {
        return [
            ...self::aboutTheContract($invoice, $tenancy),
            ...self::aboutTheParties($proposal),
            ...self::aboutTheMoney($proposal),
        ];
    }

    /**
     * @return list<string>
     */
    private static function aboutTheContract(RentInvoice $invoice, ?TenancyBrief $tenancy): array
    {
        if (null === $tenancy) {
            return ['billing.invoice.missing.tenancy'];
        }

        $from = $invoice->validity()->from();

        // Beendete Vertraege stehen bewusst zur Wahl — wer eine Rechnung von
        // damals nachtraegt, faende den Vertrag sonst nicht mehr. „Damals"
        // heisst aber waehrend der Laufzeit: ab dem Tag danach hat der
        // Vermieter nichts mehr zu leisten, und eine Rechnung darueber waere
        // ein Steuerausweis ohne Umsatz.
        if (!$tenancy->runsOn($from)) {
            return ['billing.invoice.missing.not_running'];
        }

        return null === $tenancy->stepOn($from) ? ['billing.invoice.missing.rent'] : [];
    }

    /**
     * @return list<string>
     */
    private static function aboutTheParties(ProposedInvoice $proposal): array
    {
        $missing = [];

        if ('' === $proposal->landlordName) {
            $missing[] = 'billing.invoice.missing.landlord';
        }

        if ('' === $proposal->tenantName) {
            $missing[] = 'billing.invoice.missing.tenant';
        }

        if ('' === $proposal->tenantAddress) {
            $missing[] = 'billing.invoice.missing.tenant_address';
        }

        return $missing;
    }

    /**
     * @return list<string>
     */
    private static function aboutTheMoney(ProposedInvoice $proposal): array
    {
        $missing = [];

        if ($proposal->taxation->isCharged() && '' === $proposal->landlordTaxNumber) {
            $missing[] = 'billing.invoice.missing.tax_number';
        }

        // Mit Umsatzsteuer ist die Rechnung eine zwischen Unternehmen — und
        // die gibt es ab 2028 nur noch als E-Rechnung.
        if ($proposal->taxation->isCharged()) {
            $missing = [...$missing, ...EInvoiceGaps::of($proposal->eInvoice)];
        }

        if ('' === $proposal->payeeIban) {
            $missing[] = 'billing.invoice.missing.payee';
        }

        return $missing;
    }
}
