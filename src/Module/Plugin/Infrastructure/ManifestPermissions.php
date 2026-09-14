<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Plugin\Infrastructure;

use App\Module\Plugin\Application\ActiveManifests;
use App\Shared\Security\Permission;
use App\Shared\Security\PermissionSource;

/**
 * Die Rechte der aktiven Plugins — im selben Katalog wie alle anderen.
 *
 * Ein Plugin bringt seine Rechte mit, so wie ein Modul es tut. Die
 * Rollenverwaltung soll nicht zwei Sorten Rechte kennen: eine Rolle, die
 * „Auswertungen sehen" enthaelt, ist eine Rolle wie jede andere.
 *
 * Ist ein Plugin ausgesetzt oder entfernt, verschwinden seine Rechte aus dem
 * Katalog. Zuweisungen, die daran hingen, laufen ins Leere und schaden nicht:
 * ein Recht, das es nicht gibt, wird nirgends geprueft.
 */
final readonly class ManifestPermissions implements PermissionSource
{
    public function __construct(private ActiveManifests $manifests)
    {
    }

    public function permissions(): array
    {
        $found = [];

        foreach ($this->manifests->all() as [, $manifest]) {
            foreach ($manifest->permissions as $declared) {
                $found = [...$found, ...$declared->permissions()];
            }
        }

        /** @var list<Permission> $found */
        return $found;
    }
}
