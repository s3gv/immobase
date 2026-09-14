<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Finance\UserInterface\Controller;

use App\Module\Finance\Application\SaveLoan;
use App\Module\Finance\Domain\EventBeforeTheLoan;
use App\Module\Finance\Domain\ExtraPaymentExceedsDebt;
use App\Module\Finance\Domain\FinancePermissions;
use App\Module\Finance\Domain\IncompleteLoanEvent;
use App\Module\Finance\Domain\Loan;
use App\Module\Finance\Domain\LoanEvent;
use App\Module\Finance\Domain\LoanEventKind;
use App\Shared\Money\LoanDoesNotAmortise;
use App\Shared\Money\Money;
use App\Shared\Money\MoneyInput;
use App\Shared\Money\UnreadableAmount;
use App\Shared\Time\DateInput;
use DateTimeImmutable;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Was an einem Darlehen unterwegs passiert: Sondertilgung und neuer Zins.
 *
 * Eingetragen wird der **Vorgang**, nicht sein Ergebnis — der Tilgungsplan
 * rechnet sich daraus neu. Passt er danach nicht mehr auf, kommt die Absage
 * und nichts wird gespeichert.
 */
#[IsGranted(FinancePermissions::EDIT)]
final class LoanEventController extends AbstractController
{
    public function __construct(
        private readonly RequireLoan $loan,
        private readonly SaveLoan $save,
    ) {
    }

    #[Route(
        '/finanzen/darlehen/{number}/verlauf',
        name: 'app_finance_loan_record',
        requirements: ['number' => '\d+'],
        methods: ['POST'],
    )]
    public function record(int $number, Request $request): Response
    {
        $loan = ($this->loan)($number);
        $this->guard($request);
        $kind = LoanEventKind::tryFrom($request->request->getString('kind')) ?? LoanEventKind::ExtraPayment;

        try {
            $this->recorded($loan, $kind, $request);
            $this->addFlash('success', 'finance.loan.recorded');
        } catch (UnreadableAmount) {
            $this->addFlash('error', 'finance.error.amount_invalid');
        } catch (IncompleteLoanEvent|EventBeforeTheLoan|ExtraPaymentExceedsDebt|LoanDoesNotAmortise $problem) {
            // Die Meldungen sind Uebersetzungsschluessel: die Absage kommt
            // von dort, wo die Regel steht, und nicht vom Formular.
            $this->addFlash('error', $problem->getMessage());
        }

        return $this->back($number);
    }

    #[Route(
        '/finanzen/darlehen/{number}/verlauf/{id}/loeschen',
        name: 'app_finance_loan_forget',
        requirements: ['number' => '\d+'],
        methods: ['POST'],
    )]
    public function forget(int $number, string $id, Request $request): Response
    {
        $loan = ($this->loan)($number);
        $this->guard($request);
        $this->save->forget($loan, self::eventOn($loan, $id));
        $this->addFlash('success', 'finance.loan.forgotten');

        return $this->back($number);
    }

    /**
     * Der Vorgang, so wie er im Formular stand.
     *
     * Die Zahl, die zur Art nicht gehoert, geht als `null` weiter und nicht
     * als Null: die Regel dazu steht am Ereignis, nicht hier.
     *
     * @throws UnreadableAmount
     * @throws IncompleteLoanEvent
     * @throws EventBeforeTheLoan
     * @throws ExtraPaymentExceedsDebt
     * @throws LoanDoesNotAmortise
     */
    private function recorded(Loan $loan, LoanEventKind $kind, Request $request): void
    {
        $this->save->record(
            $loan,
            $kind,
            DateInput::orNull($request, 'occurredOn') ?? new DateTimeImmutable('today'),
            $kind->needsAnAmount() ? self::amount($request) : null,
            $kind->needsAnAmount() ? null : self::rate($request),
            $request->request->getString('note'),
        );
    }

    /**
     * @throws UnreadableAmount
     */
    private static function amount(Request $request): ?Money
    {
        return MoneyInput::orNull($request->request->getString('amount'));
    }

    /**
     * Der neue Zinssatz in Basispunkten — „4,2" sind 420.
     *
     * Dieselbe Lesehilfe wie beim Betrag: sie macht aus zwei Nachkommastellen
     * eine ganze Zahl, und genau das ist ein Basispunkt.
     *
     * @throws UnreadableAmount
     */
    private static function rate(Request $request): ?int
    {
        return MoneyInput::orNull($request->request->getString('rate'))?->cents();
    }

    /** Das Ereignis muss an **diesem** Darlehen haengen — sonst gibt es das nicht. */
    private static function eventOn(Loan $loan, string $id): LoanEvent
    {
        foreach ($loan->events() as $event) {
            if ($event->id() === $id) {
                return $event;
            }
        }

        throw new NotFoundHttpException('Dieses Ereignis gibt es an diesem Darlehen nicht.');
    }

    private function back(int $number): Response
    {
        return $this->redirectToRoute('app_finance_loan_show', [
            'number' => $number,
            'abschnitt' => LoanPage::HISTORY,
        ]);
    }

    private function guard(Request $request): void
    {
        if (!$this->isCsrfTokenValid('finance_loan_event', $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('Ungültiges Formular-Token.');
        }
    }
}
