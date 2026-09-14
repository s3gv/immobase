<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Auth\Domain;

/**
 * Wo ein Konto in seinem Leben steht.
 *
 * Drei Zustaende und nicht zwei: "eingeladen" und "deaktiviert" sperren beide
 * die Anmeldung, meinen aber Verschiedenes. Nur beim ersten ist "Einladung
 * erneut schicken" die richtige Antwort, und nur dort fehlt ueberhaupt ein
 * Passwort.
 */
enum UserStatus: string
{
    case Invited = 'invited';
    case Active = 'active';
    case Deactivated = 'deactivated';

    public function labelKey(): string
    {
        return 'user.status.'.$this->value;
    }

    public function isActive(): bool
    {
        return self::Active === $this;
    }

    /**
     * Deaktiviert heisst gesperrt. "Eingeladen" allein nicht: wer seine
     * Einladung eingeloest hat, muss sich anmelden koennen — genau diese
     * erste Anmeldung macht das Konto aktiv. Ob ueberhaupt ein Passwort da
     * ist, entscheidet der Benutzer, nicht der Zustand.
     */
    public function isBlocked(): bool
    {
        return self::Deactivated === $this;
    }
}
