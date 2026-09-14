<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Finance\Application;

use App\Module\Finance\Contract\LoanBurden;
use App\Module\Finance\Contract\LoanDirectory;
use App\Module\Finance\Contract\OpenLoan;
use App\Module\Finance\Domain\Loan;
use App\Module\Finance\Domain\LoanRepository;
use App\Module\Finance\Domain\LoanSchedule;
use App\Shared\Money\Money;
use DateTimeImmutable;

/**
 * Die Darlehen eines Objekts, zusammengezaehlt.
 *
 * Jedes Darlehen bringt seinen eigenen Tilgungsplan mit; hier werden sie
 * addiert. Mehr ist es nicht — und mehr soll es nicht sein: gerechnet wird
 * an einer Stelle, und die ist {@see LoanSchedule}.
 */
final readonly class SurveyLoans implements LoanDirectory
{
    public function __construct(private LoanRepository $loans)
    {
    }

    /**
     * Was die Uebersicht der Finanzen ueber die Darlehen sagt.
     *
     * Einmal alle Darlehen holen und falten, statt je Objekt zu fragen: die
     * Uebersicht spricht ueber alle, und je Zeile eine Abfrage waere dieselbe
     * Antwort, nur oefter.
     *
     * @return array{debt: Money, burden: LoanBurden, byProperty: array<string, Money>}
     */
    public function overview(int $year): array
    {
        $debt = Money::zero();
        $interest = Money::zero();
        $principal = Money::zero();
        $byProperty = [];
        $today = new DateTimeImmutable('today');

        foreach ($this->loans->all() as $loan) {
            $schedule = LoanSchedule::of($loan);
            $open = $schedule->debtAt($today);
            $burden = $schedule->burdenIn($year);
            $debt = $debt->plus($open);
            $interest = $interest->plus($burden['interest']);
            $principal = $principal->plus($burden['principal']);
            $byProperty[$loan->propertyId()] = ($byProperty[$loan->propertyId()] ?? Money::zero())->plus($open);
        }

        return ['debt' => $debt, 'burden' => new LoanBurden($interest, $principal), 'byProperty' => $byProperty];
    }

    public function burdenIn(string $propertyId, int $year): LoanBurden
    {
        $interest = Money::zero();
        $principal = Money::zero();

        foreach ($this->loans->forProperty($propertyId) as $loan) {
            $burden = LoanSchedule::of($loan)->burdenIn($year);
            $interest = $interest->plus($burden['interest']);
            $principal = $principal->plus($burden['principal']);
        }

        return new LoanBurden($interest, $principal);
    }

    public function outstandingAt(string $propertyId, DateTimeImmutable $day): array
    {
        $open = [];

        foreach ($this->loans->forProperty($propertyId) as $loan) {
            $outstanding = LoanSchedule::of($loan)->debtAt($day);

            // Ein abgeloestes Darlehen ist keine Verbindlichkeit mehr. Es mit
            // null aufzufuehren waere eine Zeile ueber etwas, das es nicht
            // mehr gibt.
            if (!$outstanding->isZero()) {
                $open[] = new OpenLoan(self::nameOf($loan), $outstanding);
            }
        }

        return $open;
    }

    /**
     * Wie das Darlehen im Bericht heisst.
     *
     * Bezeichnung und Bank, was davon dasteht. Steht nichts da, bleibt die
     * Nummer — sie gibt es immer, und eine leere Zeile waere schlimmer. Kein
     * uebersetzter Zusatz davor: der Name wandert in ein eingefrorenes
     * Schreiben und aendert sich nicht mehr mit der Sprache des Lesers.
     */
    private static function nameOf(Loan $loan): string
    {
        $parts = array_filter(
            [$loan->label(), $loan->lender()],
            static fn (string $part): bool => '' !== $part,
        );

        return [] === $parts ? '#'.$loan->number() : implode(' · ', $parts);
    }
}
