<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Dunning\Application;

use App\Module\Dunning\Domain\BaseRateRepository;
use App\Module\Dunning\Domain\Claim;
use App\Module\Dunning\Domain\InterestSchedule;
use DateTimeImmutable;

/**
 * Die Zinsstaffel einer Forderung bis zu einem Stichtag.
 *
 * An einer Stelle, weil sie an dreien gebraucht wird: auf dem Bildschirm, im
 * Schreiben und in der Zusammenfassung fuers Amtsgericht. Zwei Rechnungen
 * ueber dieselben Zinsen liefen frueher oder spaeter auseinander, und dann
 * stuenden auf dem Papier andere Zahlen als auf dem Schirm.
 */
final readonly class TheInterest
{
    public function __construct(
        private BaseRateRepository $rates,
        private DunningSettings $settings,
    ) {
    }

    public function of(Claim $claim, DateTimeImmutable $until): InterestSchedule
    {
        return InterestSchedule::of(
            $claim->steps(),
            $this->rates->all(),
            $this->settings->pointsFor($claim),
            $until,
        );
    }
}
