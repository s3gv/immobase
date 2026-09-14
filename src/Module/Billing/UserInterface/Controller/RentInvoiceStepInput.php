<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Billing\UserInterface\Controller;

use App\Module\Billing\Application\StartRentInvoice;
use App\Module\Billing\Domain\RentInvoice;
use App\Shared\Time\DateInput;
use DateTimeImmutable;
use Symfony\Component\HttpFoundation\Request;

/**
 * Ein Schritt der Dauermietrechnung, gelesen.
 *
 * Es gibt nur einen mit Eingabefeldern. Die uebrigen zeigen, was anderswo
 * steht — und wer dort etwas aendern will, geht dorthin, statt es hier ein
 * zweites Mal einzutragen.
 */
final readonly class RentInvoiceStepInput
{
    public function __construct(private StartRentInvoice $start)
    {
    }

    /**
     * @return array<string, string>
     */
    public function apply(string $step, Request $request, RentInvoice $invoice): array
    {
        if (RentInvoiceFlow::BASICS !== $step) {
            return [];
        }

        $this->start->describe(
            $invoice,
            self::appliesFrom($request),
            $request->request->getString('label'),
        );

        return [];
    }

    /**
     * Der erste Schritt ohne Rechnung: er legt eine an.
     *
     * @return array{errors: array<string, string>, invoice: RentInvoice|null}
     */
    public function create(Request $request): array
    {
        $tenancyId = $request->request->getString('tenancyId');

        if ('' === $tenancyId) {
            return ['errors' => ['basics' => 'billing.error.invoice_needs_a_tenancy'], 'invoice' => null];
        }

        $invoice = $this->start->forTenancy(
            $tenancyId,
            self::appliesFrom($request),
            $request->request->getString('label'),
        );

        return null === $invoice
            ? ['errors' => ['basics' => 'billing.error.invoice_tenancy_unknown'], 'invoice' => null]
            : ['errors' => [], 'invoice' => $invoice];
    }

    /** Ohne Tag gilt heute — eine Rechnung ohne Leistungszeitraum gibt es nicht. */
    private static function appliesFrom(Request $request): DateTimeImmutable
    {
        return DateInput::orNull($request, 'appliesFrom') ?? new DateTimeImmutable('today');
    }
}
