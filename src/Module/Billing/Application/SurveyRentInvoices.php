<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Billing\Application;

use App\Module\Billing\Domain\RentInvoiceFilter;
use App\Module\Billing\Domain\RentInvoiceRepository;
use App\Module\Tenancy\Contract\TenancyBrief;
use DateTimeImmutable;

/**
 * Was auf der Uebersicht ueber die Dauermietrechnungen steht.
 *
 * Zwei Fragen: wie viele Entwuerfe liegen herum, und — die wichtigere —
 * welche Mietstufe hat noch keine Rechnung. Die zweite stellt niemand von
 * selbst, und genau darum muss die Uebersicht sie beantworten: eine
 * Dauermietrechnung muss **vor** der Aenderung beim Mieter sein.
 */
final readonly class SurveyRentInvoices
{
    /** Mehr als fuenf Hinweise liest niemand; der Rest steht als Zahl daneben. */
    private const int AT_MOST = 5;

    public function __construct(
        private RentInvoiceRepository $invoices,
        private StepsWithoutAnInvoice $pending,
    ) {
    }

    /**
     * @return array{drafts: int, issued: int, pending: list<array{tenancy: TenancyBrief, from: DateTimeImmutable}>, more: int}
     */
    public function overview(): array
    {
        $pending = $this->pending->all();

        return [
            'drafts' => $this->invoices->countMatching(RentInvoiceFilter::draftsOnly()),
            'issued' => $this->invoices->countMatching(RentInvoiceFilter::none())
                - $this->invoices->countMatching(RentInvoiceFilter::draftsOnly()),
            'pending' => \array_slice($pending, 0, self::AT_MOST),
            'more' => max(0, \count($pending) - self::AT_MOST),
        ];
    }
}
