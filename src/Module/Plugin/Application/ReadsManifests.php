<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Plugin\Application;

use App\Module\Plugin\Domain\Manifest\Manifest;
use App\Module\Plugin\Domain\Manifest\ManifestFault;
use App\Module\Plugin\Domain\Manifest\ManifestReader;
use App\Module\Plugin\Domain\ManifestSource;
use App\Shared\Security\PermissionSource;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * Manifeste von der Platte, gelesen und geprueft.
 *
 * Eine Stelle dafuer, weil beides sie braucht: die Uebersicht, die zeigt, was
 * gefunden wurde, und die Aktivierung, die es glaubt.
 *
 * **Ein Plugin heisst nicht wie ein Bereich der Anwendung.** Seine Rechte
 * liegen in dem Bereich, der seinen Namen traegt — ein Plugin namens `users`
 * braechte damit `users.edit` mit und stuende in der Rechtematrix dort, wo
 * ein Administrator die Benutzerverwaltung vermutet.
 */
final readonly class ReadsManifests
{
    /**
     * @param iterable<PermissionSource> $core die Rechte der Module, ohne die der Plugins
     */
    public function __construct(
        private ManifestSource $source,
        private ManifestReader $reader,
        #[AutowireIterator('security.permission_source', exclude: 'App\\Module\\Plugin\\Infrastructure\\ManifestPermissions')]
        private iterable $core = [],
    ) {
    }

    /**
     * @return array<string, string>
     */
    public function json(): array
    {
        return $this->source->all();
    }

    public function of(string $name): Manifest
    {
        $all = $this->source->all();

        if (!isset($all[$name])) {
            throw ManifestFault::at($name, 'liegt nicht unter plugins/');
        }

        return $this->ownName($this->reader->read($name, $all[$name]));
    }

    public function jsonOf(string $name): string
    {
        return $this->source->all()[$name] ?? throw ManifestFault::at($name, 'liegt nicht unter plugins/');
    }

    /** Ein abgelegtes Manifest, so wie es beim Aktivieren dastand. */
    public function stored(string $name, string $json): Manifest
    {
        return $this->ownName($this->reader->read($name, $json));
    }

    private function ownName(Manifest $manifest): Manifest
    {
        foreach ($this->core as $source) {
            foreach ($source->permissions() as $permission) {
                if ($permission->area === $manifest->name || str_starts_with($permission->area, $manifest->name.'_')) {
                    throw ManifestFault::at('name', \sprintf('„%s" ist ein Bereich der Anwendung', $manifest->name));
                }
            }
        }

        return $manifest;
    }
}
