<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Auth\Application\Rbac;

use App\Shared\Security\Permission;

/**
 * Ein Recht und woher es kommt.
 *
 * Die Herkunft steht auf der Kontoseite dabei, weil sonst die naechste Frage
 * offen bliebe — und weil sie zeigt, an wen man sich wenden muss. Am Benutzer
 * entscheidet sie ausserdem, welches Kaestchen abgeschaltet dasteht: was aus
 * einer Rolle kommt, laesst sich hier nicht wegnehmen.
 */
final readonly class PermissionOrigin
{
    /**
     * @param list<string> $roles Namen der Rollen, die dieses Recht geben
     */
    public function __construct(
        public Permission $permission,
        public array $roles,
        public bool $direct,
        public bool $bySystemRole,
    ) {
    }

    public function isGranted(): bool
    {
        return $this->bySystemRole || $this->direct || [] !== $this->roles;
    }

    /** Kommt es aus einer Rolle? Dann ist es am Benutzer nicht abwaehlbar. */
    public function fromRole(): bool
    {
        return $this->bySystemRole || [] !== $this->roles;
    }
}
