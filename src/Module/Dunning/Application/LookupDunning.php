<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Dunning\Application;

use App\Module\Dunning\Contract\DunningOverview;
use App\Module\Dunning\Contract\DunningPressure;
use App\Module\Dunning\Domain\ClaimRepository;
use App\Shared\Money\Money;
use DateTimeImmutable;

/**
 * Die Zahl am Menuepunkt.
 *
 * **Gezaehlt wird bei jedem Aufruf**, anders als beim Abzeichen der
 * Abrechnungen. Dort muss jede freigegebene Abrechnung neu gerechnet werden,
 * um zu wissen, ob eine Korrektur faellig ist — deshalb steht die Zahl dort
 * in der Sitzung. Hier sind es zwei zaehlende Abfragen ueber indizierte
 * Spalten; das kostet weniger, als es kostete, die Zahl zu merken und drei
 * Auffrischungspunkte dafuer zu pflegen.
 */
final readonly class LookupDunning implements DunningOverview
{
    public function __construct(
        private ClaimRepository $claims,
        private SurveyClaims $survey,
        private SurveyOverdue $overdue,
    ) {
    }

    public function pressure(): DunningPressure
    {
        $today = new DateTimeImmutable('today');
        $letters = [];
        $court = 0;
        $open = Money::zero();

        foreach ($this->survey->states($this->claims->allOpen(), $today) as $state) {
            $open = $open->plus($state->open);

            if ($state->needsTheCourt) {
                ++$court;
            }

            if ($state->isDue) {
                $letters[self::groupOf($state)] = true;
            }
        }

        foreach ($this->overdue->on($today) as $item) {
            $letters[$item->debtorPartyId.'|'.$item->creditor->key()] = true;
            $open = $open->plus($item->open);
        }

        return new DunningPressure(\count($letters), $court, $open);
    }

    /**
     * Ein Schreiben je Schuldner und Glaeubiger — das ist die Buendelung.
     *
     * Und der Glaeubiger ist vollstaendig gemeint: wer zwei Glaeubigern
     * schuldet, bekommt zwei Schreiben, und das Abzeichen soll zwei zeigen
     * und nicht eines.
     */
    private static function groupOf(ClaimState $state): string
    {
        return $state->claim->debtor()->partyId().'|'.$state->claim->source()->creditorIdentity()->key();
    }
}
