<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Finance\UserInterface\Controller;

use App\Module\Finance\Application\RecordPayments;
use App\Module\Finance\Domain\AdvancePaymentRepository;
use App\Module\Finance\Domain\FinancePermissions;
use App\Shared\Money\MoneyInput;
use App\Shared\Money\UnreadableAmount;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Eine Zahlung umschalten.
 *
 * Ohne Haekchen und ohne Betrag heisst: nichts gekommen. Das steht auch ueber
 * der Liste — ein leeres Feld neben einem Schalter laesst sonst offen, ob die
 * Null gemeint oder vergessen ist.
 */
#[IsGranted(FinancePermissions::EDIT)]
final class PaymentController extends AbstractController
{
    public function __construct(
        private readonly RecordPayments $payments,
        private readonly AdvancePaymentRepository $paid,
    ) {
    }

    #[Route('/finanzen/zahlungen/{id}', name: 'app_finance_payment', methods: ['POST'])]
    public function change(string $id, Request $request): Response
    {
        if (!$this->isCsrfTokenValid('finance', $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('Ungültiges Formular-Token.');
        }

        $payment = $this->paid->byId($id) ?? throw new NotFoundHttpException('Diese Zahlung gibt es nicht.');

        try {
            if ($request->request->getBoolean('settled')) {
                $this->payments->settle($payment);
            } else {
                $this->payments->miss($payment, MoneyInput::orNull($request->request->getString('part')));
            }

            $this->addFlash('success', 'finance.payment.saved');
        } catch (UnreadableAmount) {
            $this->addFlash('error', 'finance.error.amount_invalid');
        }

        return $this->redirectToRoute('app_finance_advance_show', [
            'unitId' => $payment->unitId(),
            'abschnitt' => 'zahlungen',
            'jahr' => $request->request->getString('year'),
        ]);
    }
}
