<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Finance\UserInterface\Controller;

use App\Module\Finance\Domain\Loan;
use App\Module\Finance\Domain\LoanRepository;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Das Darlehen zu einer Nummer — oder 404.
 *
 * Eigener Dienst wie {@see RequireCostItem}: drei Controller brauchen
 * dieselbe Zeile, und eine vergessene Pruefung waere eine Seite, die mit
 * `null` weiterrechnet.
 */
final readonly class RequireLoan
{
    public function __construct(private LoanRepository $loans)
    {
    }

    public function __invoke(int $number): Loan
    {
        return $this->loans->byNumber($number)
            ?? throw new NotFoundHttpException('Dieses Darlehen gibt es nicht.');
    }
}
