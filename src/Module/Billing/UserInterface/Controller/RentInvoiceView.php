<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Billing\UserInterface\Controller;

use App\Module\Billing\Application\ComposeRentInvoice;
use App\Module\Billing\Application\RentInvoiceGaps;
use App\Module\Billing\Domain\RentInvoice;
use App\Module\Tenancy\Contract\TenancyDirectory;

/**
 * Was von einer Dauermietrechnung auf dem Bildschirm steht.
 *
 * Fast alles ist gelesen und nichts davon gespeichert, solange sie Entwurf
 * ist: Betraege, Vermieter, Mieter, Konto. Genau darum stimmt die Vorschau
 * noch, wenn nebenan jemand eine Anschrift berichtigt hat — und genau darum
 * aendert sich nach der Ausstellung nichts mehr.
 */
final readonly class RentInvoiceView
{
    public function __construct(
        private ComposeRentInvoice $compose,
        private TenancyDirectory $tenancies,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function data(RentInvoice $invoice): array
    {
        $tenancy = $this->compose->tenancyOf($invoice);
        $body = $this->compose->of($invoice);

        return [
            'invoice' => $invoice,
            'body' => $body,
            'tenancy' => $tenancy,
            'step' => $this->compose->stepOf($invoice),
            'missing' => RentInvoiceGaps::of($invoice, $body, $tenancy),
        ];
    }

    /**
     * Die Mietverhaeltnisse zur Wahl — beim Anlegen.
     *
     * @return list<\App\Module\Tenancy\Contract\TenancyBrief>
     */
    public function lettable(): array
    {
        return $this->tenancies->lettable();
    }
}
