<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Auth\UserInterface\Controller;

use App\Module\Auth\Application\CheckPassword;
use App\Module\Auth\Application\ManageSecondFactor;
use App\Module\Auth\Application\Rbac\ExplainPermissions;
use App\Module\Auth\Domain\User;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Was ein Abschnitt der Kontoseite zum Zeichnen braucht.
 */
final readonly class AccountPage
{
    public function __construct(
        private ManageSecondFactor $factors,
        private CheckPassword $password,
        private ExplainPermissions $explain,
        private AccountSections $sections,
        private SecondFactorSetup $setup,
        private UrlGeneratorInterface $urls,
        private TranslatorInterface $translator,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function parameters(User $user, string $section, ?string $factorMessage = null): array
    {
        return [
            'user' => $user,
            'current' => $section,
            'sections' => $this->sections->all(),
            'heading' => $this->translator->trans('user.account.heading'),
            'subheading' => $this->translator->trans('user.account.subheading', ['%number%' => $user->number()]),
            'title' => $this->sections->title($section),
            'explanation' => $this->sections->explanation($section),
            'rules' => $this->password->rules(),
            'recoveryCodesLeft' => $this->factors->remainingRecoveryCodes($user),
            'factor' => $this->setup->offer(),
            'factorMessage' => $factorMessage,
            // Nur fuer den Abschnitt, der sie zeigt: sonst stuenden zwei
            // Abfragen unter jeder Kontoseite, die niemand liest.
            'permissions' => AccountSections::PERMISSIONS === $section
                ? $this->explain->grantedByArea($user)
                : [],
            'trail' => [
                ['label' => 'ImmoBase', 'url' => $this->urls->generate('app_dashboard')],
                ['label' => $this->translator->trans('user.account.heading'), 'url' => null],
            ],
        ];
    }
}
