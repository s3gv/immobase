<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Auth\Domain\Rbac;

use App\Shared\Identity\Uuid;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Eine Rolle: ein Name und die Rechte, die daran haengen.
 *
 * Die Rechte selbst stehen nicht hier, sondern in `auth_role_permission` —
 * eine Menge blosser Zeichenketten, die Doctrine als Entity-Beziehung nur
 * umstaendlich abbilden koennte. Sie werden ueber PermissionAssignments
 * gelesen und geschrieben.
 *
 * Wie User weder final noch readonly: Doctrine erzeugt Proxy-Klassen.
 */
#[ORM\Entity]
#[ORM\Table(name: 'auth_role')]
#[ORM\UniqueConstraint(name: 'auth_role_name', columns: ['name'])]
class Role
{
    #[ORM\Id]
    #[ORM\Column(type: Types::GUID)]
    private string $id;

    #[ORM\Column(type: Types::STRING, length: RoleName::MAX_LENGTH)]
    private string $name;

    /**
     * Die Systemrolle traegt dieses Kennzeichen und sonst niemand.
     *
     * Eine Spalte und kein Vergleich auf den Namen: waere „Administrator" das
     * Merkmal, ergaebe eine Umbenennung eine Installation ohne Systemrolle.
     */
    #[ORM\Column(type: Types::BOOLEAN)]
    private bool $isSystem;

    private function __construct(RoleName $name, bool $isSystem)
    {
        $this->id = Uuid::v4();
        $this->name = $name->toString();
        $this->isSystem = $isSystem;
    }

    public static function named(RoleName $name): self
    {
        return new self($name, false);
    }

    /** Die eine geschuetzte Rolle. Sie entsteht in der Migration. */
    public static function administrator(): self
    {
        return new self(RoleName::fromString(SystemRole::NAME), true);
    }

    public function id(): string
    {
        return $this->id;
    }

    public function name(): RoleName
    {
        return RoleName::fromString($this->name);
    }

    public function isSystem(): bool
    {
        return $this->isSystem;
    }

    /**
     * @throws RoleIsProtected
     */
    public function rename(RoleName $name): void
    {
        $this->refuseIfProtected();

        $this->name = $name->toString();
    }

    /**
     * Die Systemrolle laesst sich weder umbenennen noch loeschen, und ihre
     * Rechte lassen sich nicht aendern — sie hat immer alle.
     *
     * @throws RoleIsProtected
     */
    public function refuseIfProtected(): void
    {
        if ($this->isSystem) {
            throw new RoleIsProtected($this->name);
        }
    }
}
