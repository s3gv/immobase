<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Billing\Domain;

use App\Shared\Security\Permission;
use App\Shared\Security\PermissionSource;

/**
 * Die Rechte an den Abrechnungen.
 *
 * **Kein Loeschrecht.** Geloescht wird nur ein Entwurf, und das darf, wer
 * bearbeiten darf — auf einem Entwurf ist nichts gebaut. Eine freigegebene
 * Abrechnung wird nie geloescht: sie ist zugestellt worden, und was einmal
 * gelaufen ist, wird beendet und nicht entfernt. Ein Recht dafuer waere ein
 * Versprechen, das die Anwendung nicht halten darf.
 */
final class BillingPermissions implements PermissionSource
{
    public const string VIEW = 'billing.view';
    public const string EDIT = 'billing.edit';

    public function permissions(): array
    {
        return [
            Permission::view('billing'),
            Permission::edit('billing'),
        ];
    }
}
