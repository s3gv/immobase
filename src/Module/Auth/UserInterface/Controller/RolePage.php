<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Auth\UserInterface\Controller;

use App\Module\Auth\Application\Rbac\ManageRoles;
use App\Module\Auth\Application\Rbac\PermissionCatalogue;
use App\Module\Auth\Domain\Rbac\GrantedPermissions;
use App\Module\Auth\Domain\Rbac\PermissionAssignments;
use App\Module\Auth\Domain\Rbac\Role;
use App\Module\Auth\Domain\Rbac\RoleRepository;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Was die Seite „Rollen und Rechte" zum Zeichnen braucht.
 *
 * Zwei Abschnitte in derselben Gestalt wie „Mein Konto" und die
 * Einstellungen: links die Liste, rechts einer davon. Untereinander gestapelt
 * standen die Rollen und die Matrix als zwei Kaesten ohne erkennbaren
 * Zusammenhang da — und die Matrix ist lang genug, dass die Rollen darueber
 * ohnehin aus dem Bild rutschen.
 *
 * Getrennt vom Controller, weil es Zusammenstellung ist und keine
 * Entscheidung: Rollen, ihre Zahlen, ihre Haekchen und die Gruende, aus denen
 * ein Knopf abgeschaltet dasteht.
 */
final readonly class RolePage
{
    public const string ROLES = 'rollen';
    public const string MATRIX = 'rechte';

    public function __construct(
        private RoleRepository $roles,
        private PermissionAssignments $assignments,
        private PermissionCatalogue $catalogue,
        private ManageRoles $manage,
        private TranslatorInterface $translator,
        private UrlGeneratorInterface $urls,
    ) {
    }

    /**
     * Ein unbekannter Abschnitt aus der Adresszeile faellt auf den ersten
     * zurueck — Eingabe, kein Programmierfehler.
     */
    public static function known(string $requested): string
    {
        return self::MATRIX === $requested ? self::MATRIX : self::ROLES;
    }

    /**
     * @return array<string, mixed>
     */
    public function data(string $section): array
    {
        $roles = $this->roles->all();
        $granted = $this->assignments->ofRoles(array_map(static fn (Role $r): string => $r->id(), $roles));
        $users = $this->roles->userCounts();

        return [
            'roles' => array_map(
                fn (Role $role): array => $this->describe($role, $granted[$role->id()] ?? null, $users[$role->id()] ?? 0),
                $roles,
            ),
            'areas' => $this->catalogue->byArea(),
            ...$this->frame($section),
        ];
    }

    /**
     * Die Abschnittsliste links und die Ueberschriften rechts.
     *
     * @return array<string, mixed>
     */
    private function frame(string $section): array
    {
        return [
            'current' => $section,
            'sections' => array_map(fn (string $key): array => [
                'key' => $key,
                'label' => $this->translator->trans('role.section.'.$key),
                'url' => $this->urls->generate('app_role', ['abschnitt' => $key]),
            ], [self::ROLES, self::MATRIX]),
            'heading' => $this->translator->trans('role.heading'),
            'subheading' => $this->translator->trans('role.subheading'),
            'title' => $this->translator->trans('role.title.'.$section),
            'explanation' => $this->translator->trans('role.explanation.'.$section),
            'trail' => [
                ['label' => 'ImmoBase', 'url' => $this->urls->generate('app_dashboard')],
                ['label' => $this->translator->trans('role.heading'), 'url' => null],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function describe(Role $role, ?GrantedPermissions $granted, int $users): array
    {
        // Der Administrator steht mit allen Haekchen da — nicht, weil sie
        // gespeichert waeren, sondern weil er sie hat. Genau deshalb sind sie
        // abgeschaltet.
        $keys = $role->isSystem()
            ? $this->catalogue->keys()
            : ($granted ?? GrantedPermissions::none())->toList();

        return [
            'id' => $role->id(),
            'name' => $role->name()->toString(),
            'isSystem' => $role->isSystem(),
            'users' => $users,
            'count' => \count($keys),
            'granted' => array_flip($keys),
            'reason' => $this->manage->reasonAgainstDeleting($role),
        ];
    }
}
