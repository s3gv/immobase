<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Billing\Application;

use App\Module\Billing\Domain\RentInvoice;
use App\Module\Billing\Domain\RentInvoiceRepository;
use App\Module\Tenancy\Contract\TenancyDirectory;
use DateTimeImmutable;

/**
 * Eine Dauermietrechnung anlegen.
 *
 * Sie gehoert einem Mietverhaeltnis, und das muss in Kraft sein: ueber einen
 * Entwurf laesst sich keine Rechnung stellen. Die Fassungsnummer zaehlt je
 * Vertrag — die erste Rechnung eines jeden Mietverhaeltnisses ist die Nummer
 * eins.
 */
final readonly class StartRentInvoice
{
    public function __construct(
        private RentInvoiceRepository $invoices,
        private TenancyDirectory $tenancies,
    ) {
    }

    /** Null heisst: dieses Mietverhaeltnis gibt es nicht oder es ist ein Entwurf. */
    public function forTenancy(string $tenancyId, DateTimeImmutable $from, string $label): ?RentInvoice
    {
        $tenancy = $this->tenancies->brief($tenancyId);

        if (null === $tenancy) {
            return null;
        }

        $invoice = new RentInvoice(
            $this->invoices->nextNumberFor($tenancyId),
            $tenancy->tenancyId,
            $tenancy->number,
            $tenancy->propertyId,
            $from,
        );
        $invoice->describe($label);
        $this->invoices->save($invoice);

        return $invoice;
    }

    /**
     * Ab wann sie gilt und wie sie heisst — solange sie Entwurf ist.
     *
     * Das Mietverhaeltnis bleibt, wie es ist. Eine Rechnung, die den Vertrag
     * wechselt, waere eine andere Rechnung.
     *
     * **Und bei einer Berichtigung bleibt auch der Zeitraum.** Sie berichtigt
     * ein Schreiben, das beim Mieter liegt, und spricht darum ueber dessen
     * Zeitraum. Verschoebe sie ihn, gaebe es Tage, ueber die zwei Rechnungen
     * sprechen, und andere, ueber die keine spricht — wer den Zeitraum
     * aendern will, meint eine Folgefassung.
     */
    public function describe(RentInvoice $invoice, DateTimeImmutable $from, string $label): void
    {
        if (!$invoice->edition()->isCorrection()) {
            $invoice->appliesFrom($from);
        }

        $invoice->describe($label);
        $this->invoices->save($invoice);
    }

    /** Ein Entwurf hat nie gegolten — er wird geloescht, nicht berichtigt. */
    public function discard(RentInvoice $invoice): void
    {
        $this->invoices->remove($invoice);
    }
}
