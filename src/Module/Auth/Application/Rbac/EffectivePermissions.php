<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Auth\Application\Rbac;

use App\Module\Auth\Domain\Rbac\GrantedPermissions;
use App\Module\Auth\Domain\Rbac\PermissionAssignments;
use App\Module\Auth\Domain\Rbac\Role;
use App\Module\Auth\Domain\User;

/**
 * Was ein Konto tatsaechlich darf.
 *
 * Die Vereinigung aus den Rechten seiner Rollen und seinen direkt
 * zugewiesenen. Direkte Zuweisungen koennen nur hinzufuegen: ein Modell, in
 * dem eine Rolle etwas gibt und ein Haekchen es wieder nimmt, ist nach der
 * dritten Rolle nicht mehr zu ueberblicken.
 *
 * Der Administrator wird vor allen Tabellen beantwortet. Waere eine Zuordnung
 * kaputt, kaeme er trotzdem herein und koennte sie richten.
 *
 * **Ein Portalkonto darf nichts davon.** Es ist kein Mitarbeiter mit weniger
 * Rechten, sondern ueberhaupt keiner, und die Antwort darauf steht hier — an
 * einer Stelle statt an zweihundert `is_granted()`-Aufrufen. Selbst wenn ihm
 * jemand an der Anwendung vorbei eine Rolle in die Tabelle schriebe, bekaeme
 * es daraus kein einziges Recht.
 */
final class EffectivePermissions
{
    /** @var array<string, GrantedPermissions> */
    private array $known = [];

    public function __construct(
        private readonly PermissionAssignments $assignments,
        private readonly PermissionCatalogue $catalogue,
    ) {
    }

    public function of(User $user): GrantedPermissions
    {
        if ($user->isPortalAccount()) {
            return GrantedPermissions::none();
        }

        if ($user->isAdministrator()) {
            return GrantedPermissions::of($this->catalogue->keys());
        }

        return $this->known[$user->id()] ??= $this->gather($user);
    }

    public function allows(User $user, string $key): bool
    {
        return $this->of($user)->has($key);
    }

    /**
     * Leert den Zwischenspeicher.
     *
     * Innerhalb einer Anfrage aendern sich Rechte nur, wenn der Benutzer
     * gerade selbst welche aendert — genau dann rufen die schreibenden
     * Anwendungsfaelle hier an.
     */
    public function forget(): void
    {
        $this->known = [];
    }

    /**
     * Nur das, was aus Rollen kommt.
     *
     * Die Kontoseite eines Benutzers braucht die Trennung: was eine Rolle
     * gibt, steht dort gesetzt und abgeschaltet da — sonst saehe es aus, als
     * koennte man es unter „Zusatzrechte" wegnehmen.
     */
    public function fromRoles(User $user): GrantedPermissions
    {
        if ($user->isPortalAccount()) {
            return GrantedPermissions::none();
        }

        if ($user->isAdministrator()) {
            return GrantedPermissions::of($this->catalogue->keys());
        }

        $granted = GrantedPermissions::none();
        $roleIds = array_map(static fn (Role $role): string => $role->id(), $user->assignedRoles());

        foreach ($this->assignments->ofRoles($roleIds) as $fromRole) {
            $granted = $granted->with($fromRole);
        }

        return $granted->knownOnly($this->catalogue->keys());
    }

    private function gather(User $user): GrantedPermissions
    {
        // Zuordnungen zu Schluesseln, die es nicht mehr gibt, gewaehren
        // nichts. Sie stehen noch in der Datenbank, bis jemand
        // immobase:permission:prune laufen laesst.
        return $this->fromRoles($user)->with(
            $this->assignments->ofUser($user->id())->knownOnly($this->catalogue->keys()),
        );
    }
}
