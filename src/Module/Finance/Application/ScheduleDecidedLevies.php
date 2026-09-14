<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Finance\Application;

use App\Module\Finance\Contract\PlannedLevy;
use App\Module\Finance\Contract\SpecialLevies;
use App\Module\Finance\Contract\WithdrawnLevy;
use App\Module\Finance\Domain\AdvanceKind;
use App\Module\Finance\Domain\AdvancePayment;
use App\Module\Finance\Domain\AdvancePaymentRepository;
use App\Module\Finance\Domain\FiscalYear;
use App\Module\Property\Contract\PropertyDirectory;
use App\Module\Property\Contract\UnitDirectory;
use DateTimeImmutable;

/**
 * Beschlossene Sonderumlagen in die Zahlungen schreiben.
 *
 * Ein Flush fuer alle: die Freigabe eines Budgetplans schreibt zwanzig
 * Zahlungen auf einmal, und ein halb geschriebener Beschluss waere schlimmer
 * als keiner.
 *
 * Das Wirtschaftsjahr rechnet sich aus dem Faelligkeitstag und dem Beginn des
 * Jahres am Objekt. Es steht an der Zahlung, weil die Zahlungsseite danach
 * gruppiert — und bei einem Objekt, dessen Jahr im Juli beginnt, waere die
 * Kalenderjahreszahl die falsche Schublade.
 */
final readonly class ScheduleDecidedLevies implements SpecialLevies
{
    public function __construct(
        private AdvancePaymentRepository $payments,
        private UnitDirectory $units,
        private PropertyDirectory $properties,
    ) {
    }

    public function decided(array $levies, array $insteadOf): void
    {
        $touched = [];
        $stays = [];

        foreach ($levies as $levy) {
            $touched[] = $this->levy($levy);
            $stays[self::key($levy->unitId, $levy->dueOn, $levy->reference)] = true;
        }

        $this->payments->saveAll($touched);
        $this->payments->removeAll($this->withdrawn($insteadOf, $stays));
    }

    /**
     * Was die Fassung davor vorsah und diese nicht mehr.
     *
     * Ein Termin, den auch die neue Fassung nennt, wird nicht zurueckgenommen
     * und gleich wieder angelegt: er hat denselben Eintrag, und der traegt,
     * was jemand ueber ihn vermerkt hat.
     *
     * @param list<WithdrawnLevy> $insteadOf
     * @param array<string, true> $stays
     *
     * @return list<AdvancePayment>
     */
    private function withdrawn(array $insteadOf, array $stays): array
    {
        $gone = [];

        foreach ($insteadOf as $levy) {
            $known = isset($stays[self::key($levy->unitId, $levy->dueOn, $levy->reference)])
                ? null
                : $this->levyOf($levy->unitId, $levy->dueOn, $levy->reference);

            if (null !== $known) {
                $gone[] = $known;
            }
        }

        return $gone;
    }

    /**
     * Welche der beiden Sonderumlagen es ist.
     *
     * Der Weg steht im Beschluss; die Zahlung traegt ihn danach selbst. Wer
     * spaeter fragt, ob dieses Geld in die Abrechnung gehoert, soll nicht
     * erst den Budgetplan suchen muessen.
     */
    private static function kindOf(PlannedLevy $levy): AdvanceKind
    {
        return $levy->forTheReserve ? AdvanceKind::ReserveLevy : AdvanceKind::SpecialLevy;
    }

    /**
     * Die Sonderumlage dieses Beschlusses an diesem Tag — oder keine.
     *
     * Die Referenz gehoert zur Frage. Zwei Massnahmen duerfen dieselbe
     * Einheit am selben Tag zur Kasse bitten; das sind zwei Forderungen, und
     * die eine ist nicht die Berichtigung der anderen.
     */
    private function levyOf(string $unitId, DateTimeImmutable $dueOn, string $reference): ?AdvancePayment
    {
        foreach ($this->payments->forYear([$unitId], $this->fiscalYear($unitId, $dueOn)) as $payment) {
            $sameLevy = $payment->kind()->isALevy() && $payment->reference() === $reference;

            if ($sameLevy && self::sameDay($payment->dueOn(), $dueOn)) {
                return $payment;
            }
        }

        return null;
    }

    private static function key(string $unitId, DateTimeImmutable $dueOn, string $reference): string
    {
        return $unitId.'@'.$dueOn->format('Y-m-d').'@'.$reference;
    }

    private static function sameDay(DateTimeImmutable $one, DateTimeImmutable $other): bool
    {
        return $one->format('Y-m-d') === $other->format('Y-m-d');
    }

    private function levy(PlannedLevy $levy): AdvancePayment
    {
        $year = $this->fiscalYear($levy->unitId, $levy->dueOn);
        $known = $this->levyOf($levy->unitId, $levy->dueOn, $levy->reference);

        if (null !== $known) {
            $known->expect($levy->amount);
            $known->becomes(self::kindOf($levy));

            return $known;
        }

        return new AdvancePayment(
            $levy->unitId,
            self::kindOf($levy),
            $year,
            $levy->dueOn,
            $levy->amount,
            $levy->reference,
        );
    }

    private function fiscalYear(string $unitId, DateTimeImmutable $dueOn): int
    {
        $unit = $this->units->byIds([$unitId])[$unitId] ?? null;
        $property = null === $unit ? null : ($this->properties->byIds([$unit->propertyId])[$unit->propertyId] ?? null);

        if (null === $property) {
            return (int) $dueOn->format('Y');
        }

        return FiscalYear::of($dueOn, $property->fiscalYearDay, $property->fiscalYearMonth);
    }
}
