<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Party\Domain;

/**
 * Ob ein Stammdatensatz noch in Gebrauch ist.
 *
 * Archiviert ist die Antwort auf den Fall, dass geloescht werden soll, aber
 * nicht darf: ein Datensatz, an dem Vorgaenge haengen, verschwindet nicht
 * spurlos, sondern tritt aus dem Weg.
 */
enum PartyStatus: string
{
    case Active = 'active';
    case Archived = 'archived';

    public function labelKey(): string
    {
        return 'party.status.'.$this->value;
    }
}
