<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Auth\Domain;

use App\Module\Auth\Domain\Rbac\Role;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use InvalidArgumentException;

/**
 * Welche Rollen ein Konto traegt.
 *
 * Getrennt vom Rest wie schon {@see SignsInWithSymfony}: in `User` stehen
 * Kennung, Zustand und Zugangsdaten — wer ein Konto ist. Hier steht, was es
 * darf, und das ist eine andere Frage mit eigenen Regeln.
 *
 * Zwei davon sind hart:
 *
 * * **Ein Mitarbeiterkonto ohne Rolle gibt es nicht.** Es meldete sich an,
 *   saehe eine leere Anwendung, und niemandem fiele auf, warum.
 * * **Ein Portalkonto bekommt gar keine.** Es ist kein Mitarbeiter mit
 *   weniger Rechten, sondern ueberhaupt keiner.
 */
trait CarriesRoles
{
    /**
     * Die Rollen des Kontos — mindestens eine, siehe assignRoles().
     *
     * Eager geladen: der Rechtepruefer fragt sie bei jeder Anfrage, und ein
     * Nachladen mitten in der Autorisierung waere eine Abfrage an einer
     * Stelle, an der niemand sie vermutet.
     *
     * @var Collection<int, Role>
     */
    #[ORM\ManyToMany(targetEntity: Role::class, fetch: 'EAGER')]
    #[ORM\JoinTable(name: 'auth_user_role')]
    private Collection $roles;

    /**
     * @return list<Role>
     */
    public function assignedRoles(): array
    {
        return array_values($this->roles->toArray());
    }

    /**
     * Traegt dieses Konto die Systemrolle? Dann darf es alles.
     *
     * Ein Portalkonto niemals — auch dann nicht, wenn ihm jemand an der
     * Anwendung vorbei eine Rolle in die Tabelle schriebe.
     */
    public function isAdministrator(): bool
    {
        return !$this->isPortalAccount()
            && $this->roles->exists(static fn (int $_, Role $role): bool => $role->isSystem());
    }

    /**
     * @param list<Role> $roles
     *
     * @throws InvalidArgumentException wenn die Liste leer ist oder das Konto zum Portal gehoert
     */
    public function assignRoles(array $roles): void
    {
        if ($this->isPortalAccount()) {
            throw new InvalidArgumentException('Ein Portalkonto bekommt keine Rollen.');
        }

        if ([] === $roles) {
            throw new InvalidArgumentException('Ein Konto braucht mindestens eine Rolle.');
        }

        $this->roles = new ArrayCollection($roles);
    }

    abstract public function isPortalAccount(): bool;
}
