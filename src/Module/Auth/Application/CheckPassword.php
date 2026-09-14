<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Auth\Application;

use App\Module\Auth\Domain\PasswordRules;
use App\Module\Auth\Domain\PasswordStrength;
use App\Module\Settings\Contract\ApplicationSettings;

/**
 * Prueft ein neues Passwort gegen alle Regeln.
 *
 * An einer Stelle, weil dieselben Regeln beim Einrichten, beim Zuruecksetzen
 * und beim Aendern gelten. Drei Kopien waeren drei Gelegenheiten, eine Regel
 * zu vergessen.
 *
 * Zurueck kommt der erste Verstoss und nicht alle: eine Liste von vier
 * Fehlern auf einmal liest niemand, und wer die Laenge nicht erfuellt, muss
 * ohnehin neu tippen.
 */
final readonly class CheckPassword
{
    public function __construct(
        private LeakedPasswords $leaks,
        private ApplicationSettings $settings,
    ) {
    }

    /**
     * @param list<string> $personal Name und Adresse der Person
     *
     * @return string|null Uebersetzungsschluessel des Verstosses
     */
    public function __invoke(string $password, array $personal): ?string
    {
        if (mb_strlen($password) < PasswordRules::MINIMUM_LENGTH) {
            return 'user.password.error.length';
        }

        if (!PasswordStrength::isStrongEnough($password)) {
            return 'user.password.error.strength';
        }

        if (PasswordRules::containsPersonalData($password, $personal)) {
            return 'user.password.error.personal';
        }

        if ($this->leakCheckEnabled() && $this->leaks->isKnown($password)) {
            return 'user.password.error.leaked';
        }

        return null;
    }

    public function leakCheckEnabled(): bool
    {
        return $this->settings->bool(ApplicationSettings::LEAK_CHECK, true);
    }

    /**
     * @return list<string>
     */
    public function rules(): array
    {
        return PasswordRules::explained($this->leakCheckEnabled());
    }
}
