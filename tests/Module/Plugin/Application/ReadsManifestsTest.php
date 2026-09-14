<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Tests\Module\Plugin\Application;

use App\Module\Plugin\Application\ReadsManifests;
use App\Module\Plugin\Domain\Manifest\ManifestFault;
use App\Module\Plugin\Domain\Manifest\ManifestReader;
use App\Module\Plugin\Domain\Manifest\TableReader;
use App\Shared\Security\Permission;
use App\Shared\Security\PermissionSource;
use App\Tests\Module\Plugin\Fixture\ManifestsOnDisk;
use PHPUnit\Framework\TestCase;

/**
 * Ein Plugin heisst nicht wie ein Bereich der Anwendung — seine Rechte
 * stuenden sonst in der Matrix dort, wo ein Administrator etwas anderes
 * vermutet.
 */
final class ReadsManifestsTest extends TestCase
{
    public function testAPluginNamedLikeAModuleAreaIsRefused(): void
    {
        $this->expectException(ManifestFault::class);
        $this->expectExceptionMessageMatches('/Bereich der Anwendung/');

        self::manifests('users')->of('users');
    }

    public function testAnyOtherNameIsRead(): void
    {
        self::assertSame('reporting', self::manifests('reporting')->of('reporting')->name);
    }

    private static function manifests(string $name): ReadsManifests
    {
        $core = new class implements PermissionSource {
            public function permissions(): array
            {
                return [Permission::view('users'), Permission::edit('users')];
            }
        };

        return new ReadsManifests(
            new ManifestsOnDisk([$name => (string) json_encode(['api' => 1, 'name' => $name, 'version' => '1.0.0', 'label' => ['de' => 'Prüfung']])]),
            new ManifestReader(new TableReader()),
            [$core],
        );
    }
}
