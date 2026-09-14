<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Finance\Domain;

use App\Shared\Money\LoanDoesNotAmortise;
use App\Shared\Money\Money;
use App\Shared\Money\PlanChange;
use App\Shared\Money\RepaymentPlan;
use DateTimeImmutable;

/**
 * Der Tilgungsplan eines gefuehrten Darlehens — mit Kalender.
 *
 * {@see RepaymentPlan} rechnet in Monaten und kennt kein Datum; das ist
 * richtig so, denn die Rechnung hat mit dem Kalender nichts zu tun. Ein
 * gefuehrtes Darlehen hat aber einen: die erste Rate faellt an einem Tag, die
 * Sondertilgung auch, und das Wirtschaftsjahr fragt nach einem Jahr.
 *
 * Hier treffen sich beide. Der Monatszaehler ist die Zahl der Raten, die bis
 * zu einem Tag faellig geworden sind — die erste Rate ist Monat eins.
 */
final readonly class LoanSchedule
{
    private function __construct(public Loan $loan, public RepaymentPlan $plan)
    {
    }

    /**
     * @throws LoanDoesNotAmortise
     */
    public static function of(Loan $loan): self
    {
        $terms = $loan->terms();
        $changes = [];

        foreach ($loan->events() as $event) {
            $changes[] = new PlanChange(
                self::monthAt($loan, $event->occurredOn()),
                $event->amount(),
                $event->rateBps(),
            );
        }

        $payment = $terms->payment()
            ?? RepaymentPlan::overMonths($terms->amount(), $terms->rateBps(), $terms->months() ?? 1)->payment;

        return new self($loan, RepaymentPlan::withPayment($terms->amount(), $terms->rateBps(), $payment, $changes));
    }

    /** Der Tag der letzten Rate — ab dem Beginn so viele Monate, wie der Plan hat. */
    public function endsOn(): DateTimeImmutable
    {
        return $this->loan->terms()->startsOn()->modify(\sprintf('+%d months', $this->plan->months() - 1));
    }

    /**
     * Was am Ende dieses Tages noch offen ist.
     *
     * **Vor der ersten Rate ist nichts offen.** Der Tag der ersten Rate ist
     * der einzige, den ein Darlehen bei uns hat; wann die Bank ausgezahlt
     * hat, steht nirgends. Wer vor diesem Tag die volle Summe ausweisen
     * wuerde, schriebe sie in jeden Vermoegensbericht der Jahre davor — ein
     * Fehler um ein ganzes Darlehen. Andersherum fehlt hoechstens der Monat
     * zwischen Auszahlung und erster Rate.
     */
    public function debtAt(DateTimeImmutable $day): Money
    {
        $month = self::monthAt($this->loan, $day);

        if ($month < 1) {
            return Money::zero();
        }

        foreach ($this->plan->schedule as $row) {
            if ($row->month === $month) {
                return $row->balance;
            }
        }

        return Money::zero();
    }

    /**
     * Was ein Kalenderjahr an Zins und Tilgung kostet.
     *
     * Beides getrennt, weil es zweierlei ist: der Zins ist Aufwand, die
     * Tilgung schichtet Vermoegen um. Die Sondertilgung zaehlt zur Tilgung —
     * abgeflossen ist sie auch.
     *
     * @return array{interest: Money, principal: Money}
     */
    public function burdenIn(int $year): array
    {
        // Gezaehlt wird ab dem Stand am **Silvester davor**: monthAt des
        // ersten Januar zaehlt die Januarrate schon mit, und die gehoert in
        // dieses Jahr. Ein Jahr verloere sonst seine erste Rate.
        $first = self::monthAt($this->loan, new DateTimeImmutable(\sprintf('%d-12-31', $year - 1)));
        $last = self::monthAt($this->loan, new DateTimeImmutable(\sprintf('%d-12-31', $year)));
        $interest = Money::zero();
        $principal = Money::zero();

        foreach ($this->plan->schedule as $row) {
            if ($row->month > $first && $row->month <= $last) {
                $interest = $interest->plus($row->interest);
                $principal = $principal->plus($row->principal)->plus($row->extra());
            }
        }

        return ['interest' => $interest, 'principal' => $principal];
    }

    /**
     * Die wievielte Rate bis zu diesem Tag faellig geworden ist.
     *
     * Null heisst: das Darlehen laeuft an diesem Tag noch nicht. Gezahlt wird
     * am selben Tag des Monats wie die erste Rate; wer am Tag davor fragt,
     * bekommt den Stand vor der Rate.
     */
    public static function monthAt(Loan $loan, DateTimeImmutable $day): int
    {
        $start = $loan->terms()->startsOn();

        if ($day < $start) {
            return 0;
        }

        $months = ((int) $day->format('Y') - (int) $start->format('Y')) * 12
            + ((int) $day->format('n') - (int) $start->format('n'));

        return $months + ((int) $day->format('j') >= (int) $start->format('j') ? 1 : 0);
    }
}
