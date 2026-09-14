<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Auth\Domain;

use DateInterval;

/**
 * Wofuer ein einmaliger Schluessel ausgestellt wurde.
 *
 * Der Zweck steht am Schluessel, damit ein Einladungslink nicht als
 * Passwort-Reset durchgeht und umgekehrt. Verschiedene Zwecke haben
 * verschiedene Folgen — und wer einen Schluessel abfaengt, soll ihn nicht
 * umwidmen koennen.
 */
enum TokenPurpose: string
{
    case Invite = 'invite';
    case Reset = 'reset';
    case SecondFactor = 'second_factor';
    case EmailChange = 'email_change';

    /**
     * Wie lange er gilt.
     *
     * Ein Link muss eine Nacht ueberstehen und eine Weiterleitung im
     * Postfach; ein Code, den man gerade abtippt, nicht.
     */
    public function lifetime(): DateInterval
    {
        return new DateInterval(self::SecondFactor === $this ? 'PT10M' : 'PT48H');
    }
}
