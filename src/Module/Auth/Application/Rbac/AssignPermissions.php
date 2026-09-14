<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Auth\Application\Rbac;

use App\Module\Auth\Domain\Rbac\AuthPermissions;
use App\Module\Auth\Domain\Rbac\GrantedPermissions;
use App\Module\Auth\Domain\Rbac\NoManagerLeft;
use App\Module\Auth\Domain\Rbac\PermissionAssignments;
use App\Module\Auth\Domain\Rbac\Role;
use App\Module\Auth\Domain\Rbac\RoleRepository;
use App\Module\Auth\Domain\User;
use App\Module\Auth\Domain\UserRepository;

/**
 * Rechte an Rollen und an einzelne Konten vergeben.
 *
 * Unbekannte Schluessel kommen hier nicht hinein: was die Matrix schickt,
 * wird gegen den Katalog gefiltert. Sonst legte ein nachgebautes Formular
 * Zeilen an, die niemand mehr sieht und die kein Bereich je verlangt.
 */
final readonly class AssignPermissions
{
    public function __construct(
        private PermissionAssignments $assignments,
        private RoleRepository $roles,
        private PermissionCatalogue $catalogue,
        private EffectivePermissions $effective,
        private UserRepository $users,
    ) {
    }

    /**
     * Die ganze Matrix auf einmal.
     *
     * Ein Speichern fuer alles: wer Haekchen ueber mehrere Rollen hinweg
     * verschiebt, will einen Stand ablegen und nicht sieben.
     *
     * Geprueft wird danach, nicht davor: ob nach dem Speichern noch jemand
     * Benutzer verwalten kann, haengt an allen Haekchen zugleich, und diese
     * Frage laesst sich erst mit dem neuen Stand beantworten. Passt er nicht,
     * wird er zurueckgenommen.
     *
     * **Niemand vergibt oder entzieht ein Recht, das er selbst nicht hat.**
     * Sonst setzte, wer nur Rollen bearbeiten darf, bei seiner eigenen Rolle
     * das Haekchen bei `users.edit` und waere danach Administrator. Was
     * ausserhalb der eigenen Rechte liegt, bleibt, wie es war — egal, was das
     * Formular dazu schickt.
     *
     * @param array<string, list<string>> $byRole    Rollen-Id auf Rechteschluessel
     * @param GrantedPermissions          $grantable was der Handelnde selbst hat
     *
     * @return bool ob der Stand Bestand hat
     */
    public function saveMatrix(array $byRole, GrantedPermissions $grantable): bool
    {
        try {
            $this->users->guardingManagers(AuthPermissions::USERS_EDIT, function () use ($byRole, $grantable): void {
                $this->writeMatrix($byRole, $grantable);

                if (0 === $this->users->countActiveManagers(AuthPermissions::USERS_EDIT)) {
                    throw new NoManagerLeft();
                }
            });
        } catch (NoManagerLeft) {
            // Der Zwischenspeicher hielt kurz den verworfenen Stand.
            $this->effective->forget();

            return false;
        }

        return true;
    }

    /**
     * @param list<string> $keys
     */
    public function toRole(Role $role, array $keys): void
    {
        $role->refuseIfProtected();

        $this->assignments->setForRole($role->id(), $this->known($keys));
        $this->effective->forget();
    }

    /**
     * Zusatzrechte an einem Konto — zusaetzlich zu dem, was die Rollen geben.
     *
     * Was ohnehin aus einer Rolle kommt, wird nicht mit abgelegt: es stuende
     * sonst zweimal da, und beim Entziehen der Rolle bliebe es unbemerkt
     * stehen.
     *
     * @param list<string> $keys
     */
    public function toUser(User $user, array $keys, GrantedPermissions $fromRoles): void
    {
        $extra = GrantedPermissions::of(array_diff($this->known($keys)->toList(), $fromRoles->toList()));

        $this->assignments->setForUser($user->id(), $extra);
        $this->effective->forget();
    }

    /**
     * @param array<string, list<string>> $byRole
     */
    private function writeMatrix(array $byRole, GrantedPermissions $grantable): void
    {
        $current = $this->assignments->ofRoles(array_map(static fn (Role $role): string => $role->id(), $this->roles->all()));

        foreach ($this->roles->all() as $role) {
            if ($role->isSystem()) {
                // Sie hat immer alles. Was das Formular fuer sie schickt,
                // faellt hier weg statt in eine Ausnahme zu laufen — die
                // Kaestchen sind abgeschaltet, aber abgeschaltete Felder
                // sendet ein nachgebautes Formular trotzdem.
                continue;
            }

            $this->assignments->setForRole($role->id(), self::within(
                $this->known($byRole[$role->id()] ?? []),
                $current[$role->id()] ?? GrantedPermissions::none(),
                $grantable,
            ));
        }

        $this->effective->forget();
    }

    /** Neu, soweit es in den eigenen Rechten liegt; sonst der alte Stand. */
    private static function within(GrantedPermissions $submitted, GrantedPermissions $current, GrantedPermissions $grantable): GrantedPermissions
    {
        return GrantedPermissions::of([
            ...array_filter($submitted->toList(), $grantable->has(...)),
            ...array_filter($current->toList(), static fn (string $key): bool => !$grantable->has($key)),
        ]);
    }

    /**
     * @param list<string> $keys
     */
    private function known(array $keys): GrantedPermissions
    {
        return GrantedPermissions::of($keys)->knownOnly($this->catalogue->keys());
    }
}
