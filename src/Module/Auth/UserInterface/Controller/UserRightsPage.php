<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Auth\UserInterface\Controller;

use App\Module\Auth\Application\Rbac\ExplainPermissions;
use App\Module\Auth\Application\Rbac\PermissionCatalogue;
use App\Module\Auth\Domain\Rbac\Role;
use App\Module\Auth\Domain\Rbac\RoleRepository;
use App\Module\Auth\Domain\User;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Die Kontoseite eines Benutzers, in Abschnitten.
 *
 * Angaben, Rollen und Zusatzrechte — dieselbe Gestalt wie „Mein Konto", die
 * Einstellungen und die Rollenseite. Untereinander gestapelt wurde daraus
 * eine Seite, an deren Ende niemand mehr weiss, was oben stand.
 *
 * Rollen und Zusatzrechte stehen nur da, wer sie auch aendern darf. Ein
 * leerer Abschnitt waere ein Versprechen ohne Deckung.
 */
final readonly class UserRightsPage
{
    public const string DETAILS = 'angaben';
    public const string ROLES = 'rollen';
    public const string PERMISSIONS = 'rechte';

    public function __construct(
        private RoleRepository $roles,
        private ExplainPermissions $explain,
        private PermissionCatalogue $catalogue,
        private TranslatorInterface $translator,
        private UrlGeneratorInterface $urls,
    ) {
    }

    /**
     * Ein unbekannter oder gesperrter Abschnitt faellt auf die Angaben
     * zurueck — Eingabe, kein Programmierfehler.
     */
    public function known(string $requested, bool $mayManage): string
    {
        return \in_array($requested, self::keys($mayManage), true) ? $requested : self::DETAILS;
    }

    /**
     * @return array<string, mixed>
     */
    public function data(User $user, string $section, bool $mayManage): array
    {
        return [
            'current' => $section,
            'sections' => array_map(fn (string $key): array => [
                'key' => $key,
                'label' => $this->translator->trans('user.section.'.$key),
                'url' => $this->urls->generate('app_user_show', [
                    'number' => $user->number(),
                    'abschnitt' => $key,
                ]),
            ], self::keys($mayManage)),
            'heading' => $user->displayName(),
            'subheading' => $this->translator->trans('user.field.number').' '.$user->number(),
            'title' => $this->translator->trans('user.title.'.$section),
            'explanation' => $this->translator->trans('user.explanation.'.$section),
            'roles' => $this->roles->all(),
            'assigned' => array_flip(array_map(static fn (Role $r): string => $r->id(), $user->assignedRoles())),
            'areas' => $this->catalogue->byArea(),
            'origins' => $this->explain->forUser($user),
        ];
    }

    /**
     * @return list<string>
     */
    private static function keys(bool $mayManage): array
    {
        return $mayManage
            ? [self::DETAILS, self::ROLES, self::PERMISSIONS]
            : [self::DETAILS];
    }
}
