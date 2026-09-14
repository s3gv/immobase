<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Auth\Application;

/**
 * Steht dieses Passwort in einer bekannten Sammlung geleakter Passwoerter?
 *
 * Eine Schnittstelle, weil die Antwort von draussen kommt — und weil eine
 * Installation ohne Internetzugang trotzdem funktionieren muss.
 */
interface LeakedPasswords
{
    /**
     * Im Zweifel false.
     *
     * Ist die Auskunft nicht erreichbar, gilt das Passwort als unauffaellig.
     * Die Alternative waere, dass eine Installation ohne Internetzugang
     * niemanden mehr einrichten kann — ein Ausfall darf nicht zur Sperre
     * werden.
     */
    public function isKnown(string $password): bool;
}
