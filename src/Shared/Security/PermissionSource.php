<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Shared\Security;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Ein Bereich meldet, welche Rechte es bei ihm gibt.
 *
 * Der Katalog steht damit im Code und nicht in der Datenbank. Das
 * Vorgaengersystem hielt ihn in einer Tabelle, glich ihn per Konsolenbefehl ab
 * und liess ein CI-Tor ueber die Abweichung wachen — dazu einen eigenen
 * Anwendungsfall, um verwaiste Eintraege wieder loszuwerden. Nichts davon wird
 * gebraucht, wenn die Wahrheit ohnehin im Code steht: dann kann ein Test
 * fragen, ob eine Berechtigung ueberhaupt jemand verlangt.
 */
#[AutoconfigureTag('security.permission_source')]
interface PermissionSource
{
    /**
     * @return list<Permission>
     */
    public function permissions(): array;
}
