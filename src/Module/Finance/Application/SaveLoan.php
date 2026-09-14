<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Finance\Application;

use App\Module\Finance\Domain\EventBeforeTheLoan;
use App\Module\Finance\Domain\ExtraPaymentExceedsDebt;
use App\Module\Finance\Domain\IncompleteLoanEvent;
use App\Module\Finance\Domain\Loan;
use App\Module\Finance\Domain\LoanEvent;
use App\Module\Finance\Domain\LoanEventKind;
use App\Module\Finance\Domain\LoanRepository;
use App\Module\Finance\Domain\LoanSchedule;
use App\Module\Finance\Domain\LoanTerms;
use App\Module\Finance\Domain\UnknownProperty;
use App\Module\Property\Contract\PropertyDirectory;
use App\Shared\Money\LoanDoesNotAmortise;
use App\Shared\Money\Money;
use DateTimeImmutable;

/**
 * Ein Darlehen anlegen und pflegen.
 *
 * **Die Konditionen werden gerechnet, bevor sie gespeichert werden.** Eine
 * Rate, die den Zins nicht deckt, ergibt keinen Tilgungsplan — und ein
 * Darlehen ohne Tilgungsplan waere eine Zahl, die jede Seite danach beim
 * Zeichnen zum Absturz braechte. Die Absage kommt darum hier und nicht beim
 * Ansehen.
 */
final readonly class SaveLoan
{
    public function __construct(
        private LoanRepository $loans,
        private PropertyDirectory $properties,
    ) {
    }

    /**
     * @throws UnknownProperty
     * @throws LoanDoesNotAmortise
     */
    public function forProperty(string $propertyId, LoanTerms $terms): Loan
    {
        if ([] === $this->properties->byIds([$propertyId])) {
            throw UnknownProperty::of($propertyId);
        }

        $loan = new Loan($this->loans->nextNumber(), $propertyId, $terms);
        LoanSchedule::of($loan);
        $this->loans->save($loan);

        return $loan;
    }

    public function describe(Loan $loan, string $label, string $lender, string $note): void
    {
        $loan->describe($label, $lender);
        $loan->noteThat($note);
        $this->loans->save($loan);
    }

    /**
     * @throws LoanDoesNotAmortise
     */
    public function agreeOn(Loan $loan, LoanTerms $terms): void
    {
        $before = $loan->terms();
        $loan->agreeOn($terms);

        try {
            LoanSchedule::of($loan);
        } catch (LoanDoesNotAmortise $problem) {
            $loan->agreeOn($before);

            throw $problem;
        }

        $this->loans->save($loan);
    }

    /**
     * Ein Ereignis eintragen — und gleich pruefen, ob der Plan noch aufgeht.
     *
     * @throws IncompleteLoanEvent
     * @throws EventBeforeTheLoan
     * @throws ExtraPaymentExceedsDebt
     * @throws LoanDoesNotAmortise
     */
    public function record(
        Loan $loan,
        LoanEventKind $kind,
        DateTimeImmutable $day,
        ?Money $amount,
        ?int $rateBps,
        string $note,
    ): LoanEvent {
        self::possible($loan, $day, $amount);
        $event = new LoanEvent($loan, $kind, $day, $amount, $rateBps);
        $event->noteThat($note);

        try {
            LoanSchedule::of($loan);
        } catch (LoanDoesNotAmortise $problem) {
            $loan->forget($event);

            throw $problem;
        }

        $this->loans->save($loan);

        return $event;
    }

    public function forget(Loan $loan, LoanEvent $event): void
    {
        $loan->forget($event);
        $this->loans->save($loan);
    }

    public function remove(Loan $loan): void
    {
        $this->loans->remove($loan);
    }

    /**
     * Kann der Plan diesen Vorgang ueberhaupt aufnehmen?
     *
     * Zwei Fragen, und die Reihenfolge ist keine Geschmackssache: **erst der
     * Tag.** Vor der ersten Rate ist nichts offen, also waere jede
     * Sondertilgung dort „mehr als die Restschuld" — eine Begruendung, die
     * den wahren Grund verschweigt.
     *
     * Gefragt wird vor dem Eintragen. Danach steckt die Sondertilgung schon
     * im Plan, und die Restschuld waere die von hinterher.
     *
     * @throws EventBeforeTheLoan
     * @throws ExtraPaymentExceedsDebt
     * @throws LoanDoesNotAmortise
     */
    private static function possible(Loan $loan, DateTimeImmutable $day, ?Money $amount): void
    {
        if (LoanSchedule::monthAt($loan, $day) < 1) {
            throw EventBeforeTheLoan::of();
        }

        if (null !== $amount && $amount->cents() > LoanSchedule::of($loan)->debtAt($day)->cents()) {
            throw ExtraPaymentExceedsDebt::of();
        }
    }
}
