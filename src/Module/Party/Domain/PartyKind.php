<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Party\Domain;

/**
 * Mensch oder Firma.
 *
 * Der Unterschied ist keine Formsache: eine Firma hat keinen Vornamen, und
 * ein Anschreiben an "Herrn Musterbau GmbH" faellt sofort auf. Beides in ein
 * Namensfeld zu legen spart ein Feld und kostet die Sortierung nach Nachnamen.
 */
enum PartyKind: string
{
    case Person = 'person';
    case Company = 'company';

    public function labelKey(): string
    {
        return 'party.kind.'.$this->value;
    }
}
