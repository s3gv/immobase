<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Shared\Security;

/**
 * Was jemand in einem Bereich tun darf.
 *
 * Drei Stufen, mehr nicht. Das Vorgaengersystem hatte eine Berechtigung je
 * Aktion — payment.assign, billing.approve und so fort. Das klingt genauer und
 * ist es auch, nur konnte am Ende niemand mehr sagen, was eine Rolle
 * eigentlich darf.
 *
 * Kein "anlegen": Anlegen ist Bearbeiten. Wer einen Datensatz aendern darf,
 * darf auch einen anlegen — der Unterschied traegt keine Entscheidung.
 */
enum PermissionAction: string
{
    case View = 'view';
    case Edit = 'edit';
    case Delete = 'delete';

    public function labelKey(): string
    {
        return 'permission.action.'.$this->value;
    }
}
