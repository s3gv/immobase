<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Auth\Application\Rbac;

use App\Shared\Security\Permission;
use App\Shared\Security\PermissionSource;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * Alles, was es an Rechten gibt — eingesammelt aus den Modulen.
 *
 * Die einzige Wahrheit. Die Datenbank haelt nur Zuordnungen, und ein
 * Schluessel, der hier nicht steht, gewaehrt nichts, egal was in einer Zeile
 * steht. Das erspart den Abgleich, an dem das Vorgaengersystem einen
 * Konsolenbefehl und ein CI-Tor haengen hatte.
 */
final class PermissionCatalogue
{
    /** @var list<Permission>|null */
    private ?array $permissions = null;

    /**
     * @param iterable<PermissionSource> $sources
     */
    public function __construct(
        #[AutowireIterator('security.permission_source')]
        private readonly iterable $sources,
    ) {
    }

    /**
     * @return list<Permission>
     */
    public function all(): array
    {
        if (null !== $this->permissions) {
            return $this->permissions;
        }

        $found = [];

        foreach ($this->sources as $source) {
            foreach ($source->permissions() as $permission) {
                $found[$permission->key()] = $permission;
            }
        }

        ksort($found);

        return $this->permissions = array_values($found);
    }

    /**
     * @return list<string>
     */
    public function keys(): array
    {
        return array_map(static fn (Permission $p): string => $p->key(), $this->all());
    }

    public function has(string $key): bool
    {
        return \in_array($key, $this->keys(), true);
    }

    public function find(string $key): ?Permission
    {
        foreach ($this->all() as $permission) {
            if ($permission->key() === $key) {
                return $permission;
            }
        }

        return null;
    }

    /**
     * Nach Bereich gruppiert — so steht es in der Matrix und auf der
     * Kontoseite.
     *
     * @return array<string, list<Permission>>
     */
    public function byArea(): array
    {
        $areas = [];

        foreach ($this->all() as $permission) {
            $areas[$permission->area][] = $permission;
        }

        return $areas;
    }
}
