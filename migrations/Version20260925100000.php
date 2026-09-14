<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Die Bereiche der Rechte heissen englisch, wie ihre Module.
 *
 * Vergebene Rechte wandern mit, bei Rollen wie bei einzelnen Konten, und
 * ebenso, was ein aktiviertes Plugin im gespeicherten Manifest lesen darf —
 * sonst stuende nach dem Update niemand mehr mit einem Recht da.
 */
final class Version20260925100000 extends AbstractMigration
{
    private const array AREAS = [
        'objekte' => 'properties',
        'stammdaten' => 'parties',
        'finanzen' => 'finance',
        'miete' => 'tenancies',
        'anfragen' => 'enquiries',
        'protokoll' => 'audit',
        'benutzer' => 'users',
        'rollen' => 'roles',
        'einstellungen' => 'settings',
    ];

    public function getDescription(): string
    {
        return 'Rechtebereiche englisch benennen';
    }

    public function up(Schema $schema): void
    {
        $this->rename(self::AREAS);
    }

    public function down(Schema $schema): void
    {
        $this->rename(array_flip(self::AREAS));
    }

    /**
     * @param array<string, string> $areas
     */
    private function rename(array $areas): void
    {
        foreach ($areas as $from => $to) {
            foreach (['auth_role_permission', 'auth_user_permission'] as $table) {
                $this->addSql(
                    "UPDATE {$table} SET permission_key = :to || substr(permission_key, length(:from) + 1) WHERE permission_key LIKE :pattern",
                    ['from' => $from, 'to' => $to, 'pattern' => $from.'.%'],
                );
            }

            foreach (['view', 'edit', 'delete'] as $action) {
                $this->addSql(
                    'UPDATE plugin_installation SET manifest = replace(manifest, :old, :new)',
                    ['old' => '"'.$from.'.'.$action.'"', 'new' => '"'.$to.'.'.$action.'"'],
                );
            }
        }
    }
}
