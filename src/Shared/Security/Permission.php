<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Shared\Security;

use InvalidArgumentException;

/**
 * Ein Recht: ein Bereich und eine Aktion darin.
 *
 * Der Bereich ist das, was jemand vor sich sieht — "Stammdaten", "Benutzer" —
 * und nicht das technische Modul. Benutzerverwaltung und "Mein Konto" liegen
 * beide im Auth-Modul und sind trotzdem verschiedene Dinge: das eine verwaltet
 * fremde Konten, das andere braucht ueberhaupt kein Recht.
 *
 * Liegt in Shared, damit jedes Modul seine Rechte deklarieren kann, ohne das
 * Auth-Modul zu kennen.
 */
final readonly class Permission
{
    private const string AREA_PATTERN = '/^[a-z][a-z0-9_]*$/D';

    private function __construct(
        public string $area,
        public PermissionAction $action,
    ) {
        if (1 !== preg_match(self::AREA_PATTERN, $area)) {
            throw new InvalidArgumentException(\sprintf('Der Bereich "%s" passt nicht: Kleinbuchstaben, Ziffern und Unterstrich, beginnend mit einem Buchstaben.', $area));
        }
    }

    public static function view(string $area): self
    {
        return new self($area, PermissionAction::View);
    }

    public static function edit(string $area): self
    {
        return new self($area, PermissionAction::Edit);
    }

    public static function delete(string $area): self
    {
        return new self($area, PermissionAction::Delete);
    }

    /**
     * Erkennt, ob eine Zeichenkette ueberhaupt ein Rechteschluessel sein will.
     *
     * Gebraucht, um in #[IsGranted] die Rechte von Symfonys eigenen Rollen zu
     * unterscheiden: ROLE_USER hat keinen Punkt, parties.view schon.
     */
    public static function looksLikeKey(string $candidate): bool
    {
        return 1 === preg_match('/^[a-z][a-z0-9_]*\.(view|edit|delete)$/D', $candidate);
    }

    public function key(): string
    {
        return $this->area.'.'.$this->action->value;
    }

    public function areaLabelKey(): string
    {
        return 'permission.area.'.$this->area;
    }

    public function labelKey(): string
    {
        return 'permission.'.$this->area.'.'.$this->action->value.'.label';
    }

    public function explanationKey(): string
    {
        return 'permission.'.$this->area.'.'.$this->action->value.'.explanation';
    }
}
