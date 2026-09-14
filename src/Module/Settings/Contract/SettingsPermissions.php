<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Settings\Contract;

use App\Shared\Security\Permission;
use App\Shared\Security\PermissionSource;

/**
 * Die Rechte an den Einstellungen.
 *
 * **Im Contract und nicht in Domain**, anders als bei den uebrigen Modulen:
 * ein Rechteschluessel ist oeffentliche Flaeche, sobald ein fremdes Modul ihn
 * in `#[IsGranted]` schreibt. Die Plugin-Seite haengt unter den Einstellungen
 * und wird von dem bewacht, der sie verwaltet.
 *
 * Kein Loeschen: es gibt hier nichts, was verschwinden koennte. Eine
 * Einstellung wird geaendert, nicht entfernt — und ein Kaestchen in der
 * Matrix, das nichts bewirkt, waere eine Zusage ohne Deckung.
 */
final class SettingsPermissions implements PermissionSource
{
    public const string VIEW = 'settings.view';
    public const string EDIT = 'settings.edit';

    public function permissions(): array
    {
        return [
            Permission::view('settings'),
            Permission::edit('settings'),
        ];
    }
}
