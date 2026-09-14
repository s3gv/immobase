<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Auth\UserInterface\Controller;

use App\Module\Auth\Domain\User;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Brotkrumen und Seitenadressen der Benutzerverwaltung.
 *
 * Getrennt vom Controller, damit der bei den Aktionen bleibt.
 */
final readonly class UserPage
{
    public function __construct(
        private UrlGeneratorInterface $urls,
        private TranslatorInterface $translator,
    ) {
    }

    /**
     * Die Adresse der Liste mit __PAGE__ fuer die Seitenzahl.
     *
     * Die Filter muessen mitwandern: eine Seite zwei ohne Filter zeigt etwas
     * anderes als Seite eins mit Filter, und der Unterschied faellt erst auf,
     * wenn jemand den falschen Datensatz anfasst.
     */
    public function listUrl(Request $request): string
    {
        $parameters = array_filter([
            'q' => $request->query->getString('q'),
            'status' => $request->query->getString('status'),
            'admins' => $request->query->getBoolean('admins') ? '1' : '',
        ], static fn (string $value): bool => '' !== $value);

        return $this->urls->generate('app_user', [...$parameters, 'page' => '__PAGE__']);
    }

    /**
     * Der ganze Weg einer Einladung — der erste Schritt hier, die uebrigen
     * bei der eingeladenen Person.
     *
     * Sie stehen mit in der Liste, weil das in einem Blick erklaert, warum
     * hier nur ein Feld steht: den Rest traegt jemand anders ein.
     *
     * @return array<string, mixed>
     */
    public function invitationSections(): array
    {
        $pending = ['user.step.password.label', 'user.step.profile.label', 'user.step.factor.label'];

        return [
            'current' => 'email',
            'heading' => $this->translator->trans('user.invite.heading'),
            'subheading' => $this->translator->trans('user.invite.explanation'),
            'title' => $this->translator->trans('user.field.email'),
            'explanation' => $this->translator->trans('user.invite.section_explanation'),
            'note' => $this->translator->trans('user.invite.later_steps'),
            'sections' => [
                ['key' => 'email', 'label' => $this->translator->trans('user.field.email'), 'url' => null],
                ...array_map(fn (string $key): array => [
                    'key' => $key,
                    'label' => $this->translator->trans($key),
                    'url' => null,
                ], $pending),
            ],
        ];
    }

    /**
     * @return list<array{label: string, url: string|null}>
     */
    public function trail(?User $user): array
    {
        $trail = [
            ['label' => 'ImmoBase', 'url' => $this->urls->generate('app_dashboard')],
            ['label' => $this->translator->trans('user.heading'), 'url' => null === $user
                ? null
                : $this->urls->generate('app_user')],
        ];

        if (null !== $user) {
            $trail[] = ['label' => $user->displayName(), 'url' => null];
        }

        return $trail;
    }
}
