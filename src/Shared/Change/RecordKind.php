<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Shared\Change;

/**
 * Woran ein Vorschlag haengt.
 *
 * Eine kleine Aufzaehlung und keine Zeichenkette: sie steht in der
 * Adresszeile und in der Datenbank, und was von dort kommt, soll sich gegen
 * etwas Festes pruefen lassen.
 */
enum RecordKind: string
{
    case Party = 'stammdaten';
    case Property = 'objekt';
    case Unit = 'einheit';

    public function labelKey(): string
    {
        return 'change.record.'.$this->value;
    }
}
