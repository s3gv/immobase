<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Finance\UserInterface\Controller;

use App\Module\Finance\Domain\Loan;
use App\Module\Finance\Domain\LoanEventKind;
use App\Module\Finance\Domain\LoanSchedule;
use App\Module\Property\Contract\PropertyBrief;
use App\Module\Property\Contract\PropertyDirectory;
use App\Shared\Money\Money;
use DateTimeImmutable;

/**
 * Was von einem Darlehen auf dem Bildschirm steht.
 *
 * **Nichts davon ist gespeichert.** Restschuld, Gesamtzins, Laufzeitende und
 * die Jahresbelastung sind Auszuege aus dem gerechneten Tilgungsplan; sie
 * entstehen bei jedem Aufruf neu. Genau darum stimmen sie nach einer
 * Sondertilgung sofort.
 */
final readonly class LoanView
{
    public function __construct(private PropertyDirectory $properties)
    {
    }

    /**
     * Eine Zeile je Darlehen, fuer die Uebersicht.
     *
     * Die Objekte kommen fuer die ganze Seite auf einmal — je Zeile eine
     * Abfrage waere dieselbe Antwort, nur oefter.
     *
     * @param list<Loan> $loans
     *
     * @return list<array{number: int, property: string, label: string, lender: string, amount: Money, debt: Money, payment: Money, months: int, left: int, running: bool, startsOn: DateTimeImmutable}>
     */
    public function rows(array $loans): array
    {
        $properties = $this->properties->byIds(array_values(array_unique(
            array_map(static fn (Loan $loan): string => $loan->propertyId(), $loans),
        )));
        $today = new DateTimeImmutable('today');
        $rows = [];

        foreach ($loans as $loan) {
            $rows[] = [
                'property' => ($properties[$loan->propertyId()] ?? null)?->oneLine() ?? '',
                ...self::row($loan, $today),
            ];
        }

        return $rows;
    }

    /**
     * Alles, was die Detailseite eines Darlehens zeigt.
     *
     * @return array<string, mixed>
     */
    public function data(Loan $loan): array
    {
        $schedule = LoanSchedule::of($loan);
        $today = new DateTimeImmutable('today');

        return [
            'loan' => $loan,
            'plan' => $schedule->plan,
            'property' => $this->propertyOf($loan),
            'rate' => self::rate($loan->terms()->rateBps()),
            'debt' => $schedule->debtAt($today),
            'running' => LoanSchedule::monthAt($loan, $today) >= 1,
            'endsOn' => $schedule->endsOn(),
            'years' => $this->yearsOf($schedule),
            'rates' => self::ratesOf($loan),
            'kinds' => LoanEventKind::cases(),
        ];
    }

    /**
     * Ein Zinssatz in Basispunkten als Dezimalzahl: 420 wird zu „4.20".
     *
     * In der Schreibweise der Datenbank und nicht in der des Lesers — die
     * Vorlage schickt sie durch `|decimal`, und dort entscheidet die Sprache
     * ueber Punkt und Komma.
     */
    public static function rate(int $rateBps): string
    {
        return \sprintf('%d.%02d', intdiv($rateBps, 100), $rateBps % 100);
    }

    /**
     * Was an einer Zeile aus dem Darlehen selbst kommt.
     *
     * @return array{number: int, label: string, lender: string, amount: Money, debt: Money, payment: Money, months: int, left: int, running: bool, startsOn: DateTimeImmutable}
     */
    private static function row(Loan $loan, DateTimeImmutable $today): array
    {
        $schedule = LoanSchedule::of($loan);
        $due = LoanSchedule::monthAt($loan, $today);

        return [
            'number' => $loan->number(),
            'label' => $loan->label(),
            'lender' => $loan->lender(),
            'amount' => $loan->amount(),
            'debt' => $schedule->debtAt($today),
            'payment' => $schedule->plan->first()->payment,
            'months' => $schedule->plan->months(),
            // Was noch aussteht: die Raten des Plans minus die, die faellig
            // geworden sind. Vor dem ersten Termin sind das alle.
            'left' => max(0, $schedule->plan->months() - $due),
            // „0,00 €" an einem Darlehen, das erst naechstes Jahr anlaeuft,
            // liest sich wie „abbezahlt". Es ist das Gegenteil.
            'running' => $due >= 1,
            'startsOn' => $loan->terms()->startsOn(),
        ];
    }

    /**
     * Je Zinsaenderung ihr Satz als Dezimalzahl — fuer die Tabelle.
     *
     * Vorbereitet und nicht in der Vorlage gerechnet: eine Vorlage, die aus
     * Basispunkten Prozent macht, ist eine zweite Stelle, an der jemand
     * durch hundert teilt.
     *
     * @return array<string, string>
     */
    private static function ratesOf(Loan $loan): array
    {
        $rates = [];

        foreach ($loan->events() as $event) {
            $rates[$event->id()] = self::rate($event->rateBps() ?? 0);
        }

        return $rates;
    }

    /**
     * Der Plan nach Jahren — Zins, Tilgung und was am Jahresende offen ist.
     *
     * Monatlich waeren es bei zwanzig Jahren 240 Zeilen, und die Frage, die
     * jemand an einen Tilgungsplan hat, ist ohnehin eine jaehrliche: was
     * kostet mich das Darlehen dieses Jahr, und wann ist es weg.
     *
     * @return list<array{year: int, interest: Money, principal: Money, debt: Money}>
     */
    private function yearsOf(LoanSchedule $schedule): array
    {
        $first = (int) $schedule->loan->terms()->startsOn()->format('Y');
        $last = (int) $schedule->endsOn()->format('Y');
        $years = [];

        for ($year = $first; $year <= $last; ++$year) {
            $burden = $schedule->burdenIn($year);
            $years[] = [
                'year' => $year,
                'interest' => $burden['interest'],
                'principal' => $burden['principal'],
                'debt' => $schedule->debtAt(new DateTimeImmutable(\sprintf('%d-12-31', $year))),
            ];
        }

        return $years;
    }

    private function propertyOf(Loan $loan): ?PropertyBrief
    {
        return $this->properties->byIds([$loan->propertyId()])[$loan->propertyId()] ?? null;
    }
}
