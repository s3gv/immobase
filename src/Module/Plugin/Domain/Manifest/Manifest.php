<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Plugin\Domain\Manifest;

/**
 * Was ein Plugin ueber sich sagt.
 *
 * **Die einzige Stelle.** Rechte, Lesebereiche, Tabellen, Menuepunkte,
 * Ereignisse — alles steht hier, damit die Aktivierung eine ehrliche Frage
 * stellen kann: dieses Plugin will das und legt jenes an, einverstanden? Was
 * ein Plugin erst zur Laufzeit verlangt, hat niemand bestaetigt.
 */
final readonly class Manifest
{
    /**
     * @param array<string, string>    $labels
     * @param list<ManifestPermission> $permissions
     * @param list<string>             $reads
     * @param list<NavEntry>           $nav
     * @param list<TableSpec>          $tables
     * @param list<string>             $events
     */
    public function __construct(
        public int $apiLevel,
        public string $name,
        public string $version,
        public array $labels,
        public array $permissions,
        public array $reads,
        public array $nav,
        public array $tables,
        public array $events,
        public string $webhookPath,
        public bool $internet = false,
    ) {
    }

    public function label(string $locale): string
    {
        return $this->labels[$locale] ?? $this->labels['en'] ?? $this->name;
    }

    /**
     * Die Rechteschluessel, die dieses Plugin in den Katalog bringt.
     *
     * @return list<string>
     */
    public function permissionKeys(): array
    {
        $keys = [];

        foreach ($this->permissions as $permission) {
            foreach ($permission->permissions() as $each) {
                $keys[] = $each->key();
            }
        }

        return $keys;
    }

    /**
     * Verlangt diese Fassung mehr als die, der jemand schon zugestimmt hat?
     *
     * Sonst schliche sich ein Plugin ueber Fassungen hinweg Rechte an, die
     * nie jemand gegeben hat — auch den Weg ins Internet.
     */
    public function wantsMoreThan(self $agreed): bool
    {
        $before = [...$agreed->permissionKeys(), ...$agreed->reads];
        $now = [...$this->permissionKeys(), ...$this->reads];

        return [] !== array_diff($now, $before) || ($this->internet && !$agreed->internet);
    }
}
