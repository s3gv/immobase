<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Dunning\Application;

use App\Module\Dunning\Domain\Claim;
use App\Module\Dunning\Domain\DunningLevel;
use App\Module\Settings\Contract\ApplicationSettings;
use App\Shared\Money\Money;

/**
 * Die Zahlen des Mahnwesens, mit ihren Vorgaben.
 *
 * An einer Stelle, damit nicht jede Seite ihre eigene Vorgabe erfindet. Die
 * Vorgaben sind bewusst zurueckhaltend:
 *
 * * **Mahnkosten null.** Erstattungsfaehig sind nur die tatsaechlichen Kosten
 *   fuer Papier, Umschlag und Porto (BGH VIII ZR 95/18) — wer sie ansetzt,
 *   soll die eigenen eintragen und nicht eine Zahl uebernehmen, die wir uns
 *   ausgedacht haben.
 * * **Fuenf und neun Prozentpunkte** nach § 288 Abs. 1 und 2 BGB.
 * * **Sieben, zehn und vierzehn Tage** — angemessen ist, was dem Schuldner
 *   das Zahlen ermoeglicht, und mit jeder Stufe waechst der Ernst.
 */
final readonly class DunningSettings
{
    public function __construct(private ApplicationSettings $settings)
    {
    }

    public function daysFor(DunningLevel $level): int
    {
        return match ($level) {
            DunningLevel::Reminder => $this->settings->int(ApplicationSettings::DUNNING_DAYS_REMINDER, 7),
            DunningLevel::First => $this->settings->int(ApplicationSettings::DUNNING_DAYS_FIRST, 10),
            DunningLevel::Final => $this->settings->int(ApplicationSettings::DUNNING_DAYS_FINAL, 14),
        };
    }

    /** Die Zahlungserinnerung traegt nie Kosten — sie loest den Verzug erst aus. */
    public function costsFor(DunningLevel $level): Money
    {
        return match ($level) {
            DunningLevel::Reminder => Money::zero(),
            DunningLevel::First => Money::fromCents($this->settings->int(ApplicationSettings::DUNNING_COSTS_FIRST, 0)),
            DunningLevel::Final => Money::fromCents($this->settings->int(ApplicationSettings::DUNNING_COSTS_FINAL, 0)),
        };
    }

    /** Fuenf Punkte, neun ohne Verbraucher. */
    public function pointsFor(Claim $claim): int
    {
        return $claim->debtor()->isCommercial()
            ? $this->settings->int(ApplicationSettings::DUNNING_POINTS_COMMERCIAL, 900)
            : $this->settings->int(ApplicationSettings::DUNNING_POINTS_CONSUMER, 500);
    }

    public function flatFee(): Money
    {
        return Money::fromCents($this->settings->int(ApplicationSettings::DUNNING_FLAT_FEE, 4000));
    }
}
