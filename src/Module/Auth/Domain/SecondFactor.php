<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Auth\Domain;

/**
 * Womit sich jemand zusaetzlich zum Passwort ausweist.
 *
 * "Keiner" ist ausdruecklich erlaubt. Ein zweiter Faktor, den man nicht
 * ueberspringen kann, sperrt am Ende die aus, die kein zweites Geraet haben —
 * und dann wird er umgangen statt benutzt.
 */
enum SecondFactor: string
{
    case None = 'none';
    case App = 'app';
    case Email = 'email';

    public function labelKey(): string
    {
        return 'user.factor.'.$this->value;
    }

    public function isSet(): bool
    {
        return self::None !== $this;
    }
}
