<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Billing\UserInterface\Controller;

use App\Module\Billing\Application\FundingSchedules;
use App\Module\Billing\Domain\Budget;
use App\Module\Billing\Domain\BudgetRepository;
use App\Module\Billing\Domain\Funding;
use App\Module\Billing\Domain\LevyPurpose;
use App\Module\Finance\Contract\Interval;
use App\Shared\Http\FormInput;
use App\Shared\Money\LoanDoesNotAmortise;
use App\Shared\Money\Money;
use App\Shared\Money\MoneyInput;
use App\Shared\Money\UnreadableAmount;
use DateTimeImmutable;
use Symfony\Component\HttpFoundation\Request;

/**
 * Der Finanzierungsschritt, gelesen.
 *
 * Ein eigener Leser und nicht eine weitere Methode im Schritt-Eingang: hier
 * kommen vier Wege mit zwoelf Feldern herein, und jeder von ihnen kann auf
 * seine eigene Art unlesbar sein.
 *
 * **Der Zinssatz ist die heikelste Eingabe.** „4,2" heisst 4,2 Prozent und
 * wird zu 420 Basispunkten — ohne Fliesskomma, sonst faengt genau hier an, was
 * der Tilgungsplan vermeiden soll.
 */
final readonly class BudgetFunding
{
    public function __construct(private BudgetRepository $budgets)
    {
    }

    /**
     * @return array<string, string>
     */
    public function apply(Request $request, Budget $budget): array
    {
        try {
            $funding = self::readFrom($request);
        } catch (UnreadableAmount) {
            return ['finanzierung' => 'billing.budget.error.amount'];
        }

        // Der Tilgungsplan wird versucht, **bevor** etwas gespeichert wird:
        // geht er nicht auf, ist die Eingabe falsch und nicht die Rechnung.
        // Am Plan darf sie dann gar nicht erst stehen — die naechste Seite
        // wuerde sonst beim Zeichnen ueber dieselbe Zahl stolpern.
        try {
            FundingSchedules::repayment($funding);
        } catch (LoanDoesNotAmortise $problem) {
            return ['loan' => $problem->getMessage()];
        }

        $budget->fund($funding);
        $this->budgets->save($budget);

        return [];
    }

    /**
     * @throws UnreadableAmount
     */
    private static function readFrom(Request $request): Funding
    {
        $funding = Funding::none()
            ->fromTheReserve(self::money($request, 'reserve'))
            ->byLevy(
                self::money($request, 'levy'),
                self::dayOrNull($request->request->getString('levyDueOn')),
                FormInput::intOrNull($request, 'levyParts') ?? 1,
                Interval::tryFrom($request->request->getString('levyInterval')) ?? Interval::Monthly,
                LevyPurpose::tryFrom($request->request->getString('levyPurpose')) ?? LevyPurpose::ForTheMeasure,
            )
            ->bySaving(
                self::money($request, 'saving'),
                FormInput::intOrNull($request, 'savingYears') ?? 0,
                FormInput::intOrNull($request, 'savingFrom') ?? 0,
            );

        return $funding->byLoan(
            self::money($request, 'loan'),
            self::basisPoints($request->request->getString('loanRate')),
            MoneyInput::orNull($request->request->getString('loanPayment')),
            FormInput::intOrNull($request, 'loanMonths'),
        );
    }

    /**
     * Ein Prozentsatz als Basispunkte — „4,2" wird zu 420.
     *
     * Ueber die Cent-Lesehilfe und nicht ueber `(float)`: sie zerlegt die
     * Zeichenkette und setzt sie als ganze Zahl wieder zusammen. Zwei
     * Nachkommastellen sind bei einem Zinssatz genau die Genauigkeit, die eine
     * Bank nennt.
     *
     * @throws UnreadableAmount
     */
    private static function basisPoints(string $value): int
    {
        return MoneyInput::orNull($value)?->cents() ?? 0;
    }

    /**
     * @throws UnreadableAmount
     */
    private static function money(Request $request, string $field): Money
    {
        return MoneyInput::orNull($request->request->getString($field)) ?? Money::zero();
    }

    private static function dayOrNull(string $value): ?DateTimeImmutable
    {
        $day = DateTimeImmutable::createFromFormat('!Y-m-d', trim($value));

        return false === $day ? null : $day;
    }
}
