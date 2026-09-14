<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Dunning\UserInterface\Controller;

use App\Module\Dunning\Application\Addressed;
use App\Module\Dunning\Application\SurveyClaims;
use App\Module\Dunning\Application\TheInterest;
use App\Module\Dunning\Domain\Claim;
use App\Module\Dunning\Domain\NoticeRepository;
use App\Module\Tenancy\Contract\TenancyDirectory;
use DateTimeImmutable;

/**
 * Was auf der Detailseite einer Forderung steht.
 *
 * Fast alles gerechnet: der Zustand, die Zinsstaffel, das Verhaeltnis zur
 * Monatsmiete. Gespeichert ist nur die Forderung selbst und was ueber sie
 * hinausging.
 */
final readonly class DunningView
{
    public function __construct(
        private TheInterest $interest,
        private SurveyClaims $survey,
        private NoticeRepository $notices,
        private Addressed $addressed,
        private TenancyDirectory $tenancies,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function data(Claim $claim): array
    {
        $today = new DateTimeImmutable('today');
        $until = $claim->arrears()->settledOn() ?? $today;

        return [
            'claim' => $claim,
            'state' => $this->survey->of($claim, $today),
            'schedule' => $this->interest->of($claim, $until),
            'notices' => $this->notices->forClaims([$claim->id()]),
            'debtor' => $this->addressed->debtorOf($claim),
            'creditor' => $this->addressed->creditorOf($claim),
            'monthsOfRent' => $this->monthsOfRent($claim),
            'today' => $today,
        ];
    }

    /**
     * Wie viele Monatsmieten der Rueckstand ausmacht — oder null.
     *
     * **Eine Tatsache, keine Folgerung.** Welche Folge ein Rueckstand in
     * dieser Hoehe hat, haengt an Umstaenden, die keine Software beurteilt;
     * die Zahl selbst ist nachrechenbar und hilft dem Verwalter, der den
     * Vermieter anruft.
     *
     * Gerechnet auf die Gesamtmiete des Tages der Faelligkeit — Kaltmiete
     * plus Vorauszahlungen, so wie der Mietvertrag sie nennt. Ohne
     * Mietverhaeltnis an der Einheit gibt es die Zahl nicht; bei einem
     * Hausgeld waere sie auch ohne Sinn.
     *
     * Zurueck kommt sie maschinenlesbar mit Punkt; die Vorlage schreibt sie
     * in der Schreibweise der Sprache. „0,8" in einem englischen Satz waere
     * eine zweite Schreibweise auf demselben Blatt.
     */
    private function monthsOfRent(Claim $claim): ?string
    {
        $unitId = $claim->source()->unitId();

        if (null === $unitId) {
            return null;
        }

        foreach ($this->tenancies->lettable() as $tenancy) {
            if ($tenancy->unitId !== $unitId || !$tenancy->runsOn($claim->arrears()->dueOn())) {
                continue;
            }

            $step = $tenancy->stepOn($claim->arrears()->dueOn());
            $rent = $step?->total();

            if (null === $rent || $rent->isZero()) {
                return null;
            }

            // Ganzzahlig auf eine Nachkommastelle: zehnmal der Rueckstand
            // durch die Miete, dann der Punkt davor. Fliesskomma braucht es
            // dafuer nicht.
            $tenths = intdiv(10 * $claim->open()->cents() + intdiv($rent->cents(), 2), $rent->cents());

            return intdiv($tenths, 10).'.'.($tenths % 10);
        }

        return null;
    }
}
