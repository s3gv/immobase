<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Finance\UserInterface\Controller;

use App\Module\Finance\Application\SaveLoan;
use App\Module\Finance\Domain\Loan;
use App\Module\Finance\Domain\LoanTerms;
use App\Module\Finance\Domain\UnknownProperty;
use App\Shared\Http\FormInput;
use App\Shared\Money\LoanDoesNotAmortise;
use App\Shared\Money\Money;
use App\Shared\Money\MoneyInput;
use App\Shared\Money\UnreadableAmount;
use App\Shared\Time\DateInput;
use DateTimeImmutable;
use Symfony\Component\HttpFoundation\Request;

/**
 * Ein Schritt des Darlehens, gelesen.
 *
 * **Der Zinssatz ist die heikelste Eingabe** — „4,2" heisst 4,2 Prozent und
 * wird zu 420 Basispunkten, ohne Fliesskomma. Dieselbe Lesehilfe wie beim
 * Budgetplan: sie zerlegt die Zeichenkette und setzt sie als ganze Zahl
 * wieder zusammen.
 */
final readonly class LoanStepInput
{
    public function __construct(private SaveLoan $save)
    {
    }

    /**
     * @return array<string, string>
     */
    public function apply(string $step, Request $request, Loan $loan): array
    {
        if (LoanFlow::TERMS === $step) {
            return $this->terms($request, $loan);
        }

        $this->save->describe(
            $loan,
            $request->request->getString('label'),
            $request->request->getString('lender'),
            $request->request->getString('note'),
        );

        return [];
    }

    /**
     * Der erste Schritt ohne Darlehen: er legt eines an.
     *
     * @return array{errors: array<string, string>, loan: Loan|null}
     */
    public function create(Request $request): array
    {
        $propertyId = $request->request->getString('propertyId');

        // Nichts gewaehlt ist etwas anderes als etwas Falsches gewaehlt:
        // „gibt es nicht mehr" waere eine Antwort auf eine Frage, die
        // niemand gestellt hat.
        if ('' === $propertyId) {
            return ['errors' => ['basics' => 'finance.error.key_needs_property'], 'loan' => null];
        }

        try {
            $loan = $this->save->forProperty($propertyId, self::openTerms());
        } catch (UnknownProperty) {
            return ['errors' => ['basics' => 'finance.error.property_unknown'], 'loan' => null];
        }

        $this->save->describe(
            $loan,
            $request->request->getString('label'),
            $request->request->getString('lender'),
            $request->request->getString('note'),
        );

        return ['errors' => [], 'loan' => $loan];
    }

    /**
     * @return array<string, string>
     */
    private function terms(Request $request, Loan $loan): array
    {
        try {
            $missing = self::missing($request);

            if (null !== $missing) {
                return ['terms' => $missing];
            }

            $this->save->agreeOn($loan, self::agreed($request));
        } catch (UnreadableAmount) {
            return ['terms' => 'finance.error.amount_invalid'];
        } catch (LoanDoesNotAmortise $problem) {
            return ['terms' => $problem->getMessage()];
        }

        return [];
    }

    /**
     * Was fehlt, damit sich ueberhaupt ein Plan rechnen laesst.
     *
     * Ohne Summe gibt es nichts zu tilgen, und ohne Rate **und** Laufzeit
     * waere jede Zahl geraten. Der Zins darf null sein — ein zinsloses
     * Darlehen von der Kommune ist keines mit fehlender Angabe.
     *
     * @throws UnreadableAmount
     */
    private static function missing(Request $request): ?string
    {
        $amount = MoneyInput::orNull($request->request->getString('amount'));

        if (null === $amount || $amount->isZero()) {
            return 'finance.error.loan_amount_missing';
        }

        $payment = MoneyInput::orNull($request->request->getString('payment'));
        $months = FormInput::intOrNull($request, 'months');

        return (null === $payment || $payment->isZero()) && (null === $months || 0 === $months)
            ? 'finance.error.loan_term_missing'
            : null;
    }

    /**
     * Was eingetragen wurde: Rate **oder** Laufzeit.
     *
     * Steht beides da, gilt die Rate — sie ist das, was die Bank nennt, und
     * die Laufzeit rechnet sich daraus.
     *
     * @throws UnreadableAmount
     */
    private static function agreed(Request $request): LoanTerms
    {
        $amount = MoneyInput::orNull($request->request->getString('amount')) ?? Money::zero();
        $rateBps = MoneyInput::orNull($request->request->getString('rate'))?->cents() ?? 0;
        $startsOn = DateInput::orNull($request, 'startsOn') ?? new DateTimeImmutable('today');
        $payment = MoneyInput::orNull($request->request->getString('payment'));

        if (null !== $payment && !$payment->isZero()) {
            return LoanTerms::withPayment($amount, $rateBps, $startsOn, $payment);
        }

        return LoanTerms::overMonths($amount, $rateBps, $startsOn, FormInput::intOrNull($request, 'months') ?? 0);
    }

    /** Beim Anlegen steht noch nichts fest — eine Laufzeit von einem Monat haelt die Stelle. */
    private static function openTerms(): LoanTerms
    {
        return LoanTerms::overMonths(Money::zero(), 0, new DateTimeImmutable('today'), 1);
    }
}
