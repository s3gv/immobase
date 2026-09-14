<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Finance\UserInterface\Controller;

use App\Module\Finance\Application\RecordPayments;
use App\Module\Finance\Domain\AdvancePayment;
use App\Module\Finance\Domain\FinancePermissions;
use App\Module\Property\Contract\UnitBrief;
use App\Shared\Http\FormInput;
use App\Shared\Money\Money;
use DateTimeImmutable;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;

/**
 * Was der Zahlungsabschnitt anzuzeigen hat.
 *
 * Getrennt vom Controller, damit der bei den Aktionen bleibt — und weil die
 * Eintraege beim Ansehen entstehen: welche Faelligkeiten ein Jahr hat, haengt
 * an der Staffel, und die aendert sich.
 */
final readonly class PaymentView
{
    /** So weit zurueck laesst sich ein Jahr waehlen. */
    private const int YEARS_BACK = 4;

    /**
     * Und so weit nach vorn.
     *
     * Ein beschlossener Wirtschaftsplan gilt ab dem naechsten Jahr, und eine
     * beschlossene Sonderumlage ist womoeglich erst uebernaechstes Jahr
     * faellig. Beides steht dann schon fest — es waere seltsam, wenn die
     * Zahlungsseite es erst zeigte, wenn es so weit ist.
     */
    private const int YEARS_AHEAD = 2;

    public function __construct(
        private RecordPayments $payments,
        private Security $security,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function of(UnitBrief $unit, Request $request): array
    {
        $thisYear = (int) (new DateTimeImmutable('today'))->format('Y');
        $years = range($thisYear + self::YEARS_AHEAD, $thisYear - self::YEARS_BACK);
        $chosen = FormInput::queryIntOrNull($request, 'jahr') ?? $thisYear;
        $chosen = \in_array($chosen, $years, true) ? $chosen : $thisYear;
        $payments = $this->payments->forYear($unit->id, $chosen);

        return [
            'payments' => $payments,
            'years' => $years,
            'chosenYear' => $chosen,
            'may_edit' => $this->security->isGranted(FinancePermissions::EDIT),
            'expectedTotal' => self::sum($payments, static fn (AdvancePayment $p): Money => $p->expected()),
            'receivedTotal' => self::sum($payments, static fn (AdvancePayment $p): Money => $p->received()),
        ];
    }

    /**
     * @param list<AdvancePayment>            $payments
     * @param callable(AdvancePayment): Money $of
     */
    private static function sum(array $payments, callable $of): Money
    {
        $total = Money::zero();

        foreach ($payments as $payment) {
            $total = $total->plus($of($payment));
        }

        return $total;
    }
}
