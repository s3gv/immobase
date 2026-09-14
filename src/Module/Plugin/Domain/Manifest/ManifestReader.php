<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Plugin\Domain\Manifest;

use App\Shared\Security\Permission;
use App\Shared\Security\PermissionAction;

/**
 * Liest ein Manifest und glaubt ihm nichts.
 *
 * Alles, was hier durchkommt, geht spaeter in Rechte, in DDL und in Adressen,
 * die der Core selbst aufruft. Namen sind deshalb eng gefasst, Pfade duerfen
 * das Plugin nicht verlassen, und ein unbekannter Stand von `api` wird gar
 * nicht erst gelesen: eine kuenftige Fassung darf Felder anders meinen, und
 * ein alter Leser wuerde sie falsch verstehen statt sie abzulehnen.
 */
final readonly class ManifestReader
{
    /** Der Stand, den dieser Core versteht. */
    public const int API = 1;

    private const string NAME = '/^[a-z][a-z0-9_]{0,31}$/D';
    private const string VERSION = '/^[0-9]+\.[0-9]+\.[0-9]+$/D';
    private const string EVENT = '/^([a-z][a-z0-9-]*|\*)\.(created|updated|deleted|\*)$/D';

    public function __construct(private TableReader $tables)
    {
    }

    public function read(string $directory, string $json): Manifest
    {
        $fields = $this->decode($json);
        $level = $fields->integer('api');

        if (self::API !== $level) {
            throw ManifestFault::at('api', \sprintf('Stand %d wird von dieser Fassung nicht unterstützt, erwartet ist %d', $level, self::API));
        }

        $name = $fields->text('name', self::NAME);

        if ($name !== $directory) {
            throw ManifestFault::at('name', \sprintf('„%s" steht in einem Verzeichnis namens „%s"', $name, $directory));
        }

        return $this->build($name, $fields);
    }

    private function build(string $name, Fields $fields): Manifest
    {
        $permissions = $this->permissions($fields, $name);
        $keys = [];

        foreach ($permissions as $permission) {
            foreach ($permission->permissions() as $each) {
                $keys[] = $each->key();
            }
        }

        return new Manifest(
            self::API,
            $name,
            $fields->text('version', self::VERSION),
            $fields->labels('label'),
            $permissions,
            $this->reads($fields),
            $this->nav($fields, $keys),
            $this->tables->from($fields),
            $fields->strings('events', self::EVENT),
            $this->path($fields, 'webhook_path', '/webhook'),
            // Ins Internet nur, wer es ausdruecklich sagt — und wem beim
            // Aktivieren jemand zustimmt. Ohne Angabe: nein.
            $fields->flag('internet'),
        );
    }

    private function decode(string $json): Fields
    {
        /** @var mixed $data */
        $data = json_decode($json, true);

        if (!\is_array($data)) {
            throw ManifestFault::at('manifest.json', 'ist kein JSON-Objekt');
        }

        return new Fields($data, '');
    }

    /**
     * Die Rechte des Plugins — in seinem eigenen Bereich.
     *
     * Ein Bereich heisst wie das Plugin oder beginnt mit dessen Namen und
     * einem Unterstrich. Sonst trueg ein Plugin `users` als Bereich ein, und
     * die Rechtematrix zeigte bei „Benutzer verwalten" seine Beschriftung: ein
     * Administrator hakte an, was er fuer etwas ganz anderes haelt.
     *
     * @return list<ManifestPermission>
     */
    private function permissions(Fields $fields, string $name): array
    {
        $found = [];

        foreach ($fields->each('permissions') as $entry) {
            $actions = array_map(
                static fn (string $action): PermissionAction => PermissionAction::tryFrom($action)
                    ?? throw ManifestFault::at('permissions.actions', \sprintf('„%s" ist keine Aktion', $action)),
                $entry->strings('actions'),
            );

            if ([] === $actions) {
                throw ManifestFault::at('permissions.actions', 'erwartet mindestens eine Aktion');
            }

            $area = $entry->text('area', self::NAME);

            if ($area !== $name && !str_starts_with($area, $name.'_')) {
                throw ManifestFault::at('permissions.area', \sprintf('„%s" gehört nicht zu diesem Plugin — erwartet „%s" oder „%s_…"', $area, $name, $name));
            }

            $found[] = new ManifestPermission($area, $actions, $entry->labels('label'));
        }

        return $found;
    }

    /**
     * Die Bereiche, die das Token lesen darf.
     *
     * @return list<string>
     */
    private function reads(Fields $fields): array
    {
        $reads = $fields->strings('reads');

        foreach ($reads as $key) {
            if (!Permission::looksLikeKey($key)) {
                throw ManifestFault::at('reads', \sprintf('„%s" ist kein Rechteschlüssel', $key));
            }
        }

        return $reads;
    }

    /**
     * @param list<string> $ownKeys
     *
     * @return list<NavEntry>
     */
    private function nav(Fields $fields, array $ownKeys): array
    {
        $found = [];

        foreach ($fields->each('nav') as $entry) {
            $permission = $entry->text('permission');

            if (!\in_array($permission, $ownKeys, true)) {
                throw ManifestFault::at('nav.permission', \sprintf('„%s" ist kein Recht dieses Plugins', $permission));
            }

            $icon = $entry->textOr('icon', 'puzzle');

            if (!\in_array($icon, NavEntry::ICONS, true)) {
                throw ManifestFault::at('nav.icon', \sprintf('„%s" ist keines der verfügbaren Symbole', $icon));
            }

            $found[] = new NavEntry($this->path($entry, 'path', ''), $entry->labels('label'), $permission, $icon);
        }

        return $found;
    }

    /**
     * Ein Pfad beim Plugin.
     *
     * Er beginnt mit einem Schraegstrich und fuehrt nicht heraus: weder in
     * ein anderes Verzeichnis noch, mit zwei Schraegstrichen am Anfang, auf
     * einen fremden Rechner.
     */
    private function path(Fields $fields, string $key, string $fallback): string
    {
        $path = '' === $fallback ? $fields->text($key) : $fields->textOr($key, $fallback);

        if (!str_starts_with($path, '/') || str_starts_with($path, '//') || str_contains($path, '..')) {
            throw ManifestFault::at($key, \sprintf('„%s" ist kein Pfad innerhalb des Plugins', $path));
        }

        return $path;
    }
}
