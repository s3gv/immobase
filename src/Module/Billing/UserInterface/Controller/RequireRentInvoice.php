<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Billing\UserInterface\Controller;

use App\Module\Billing\Domain\RentInvoice;
use App\Module\Billing\Domain\RentInvoiceRepository;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Die Dauermietrechnung zu einer Kennung — oder 404.
 *
 * Eigener Dienst: drei Controller brauchen dieselbe Zeile, und eine
 * vergessene Pruefung waere eine Seite, die mit `null` weiterrechnet.
 */
final readonly class RequireRentInvoice
{
    public function __construct(private RentInvoiceRepository $invoices)
    {
    }

    public function __invoke(string $id): RentInvoice
    {
        return $this->invoices->byId($id)
            ?? throw new NotFoundHttpException('Diese Dauermietrechnung gibt es nicht.');
    }
}
