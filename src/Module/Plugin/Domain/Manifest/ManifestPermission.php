<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Plugin\Domain\Manifest;

use App\Shared\Security\Permission;
use App\Shared\Security\PermissionAction;

/**
 * Ein Recht, das ein Plugin mitbringt.
 *
 * Es geht in denselben Katalog wie die Rechte der Module — die
 * Rollenverwaltung soll nicht zwei Sorten Rechte kennen. Nur die
 * Beschriftung kommt aus dem Manifest statt aus den Uebersetzungsdateien:
 * der Core kennt den Wortlaut eines fremden Plugins nicht.
 */
final readonly class ManifestPermission
{
    /**
     * @param non-empty-list<PermissionAction> $actions
     * @param array<string, string>            $labels
     */
    public function __construct(
        public string $area,
        public array $actions,
        public array $labels,
    ) {
    }

    /**
     * @return non-empty-list<Permission>
     */
    public function permissions(): array
    {
        return array_map(fn (PermissionAction $action): Permission => match ($action) {
            PermissionAction::View => Permission::view($this->area),
            PermissionAction::Edit => Permission::edit($this->area),
            PermissionAction::Delete => Permission::delete($this->area),
        }, $this->actions);
    }
}
