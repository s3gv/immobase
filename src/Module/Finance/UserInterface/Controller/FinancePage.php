<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Finance\UserInterface\Controller;

use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Die Brotkrumen der Finanzseiten.
 *
 * Als eigener Dienst, damit die Controller nicht uebersetzen: sie
 * entscheiden, was passiert, nicht wie es heisst.
 */
final readonly class FinancePage
{
    public function __construct(
        private UrlGeneratorInterface $urls,
        private TranslatorInterface $translator,
    ) {
    }

    /**
     * Der Pfad bis zu dieser Seite.
     *
     * @param string ...$keys Uebersetzungsschluessel der Unterseiten
     *
     * @return list<array{label: string, url: string|null}>
     */
    public function trail(string ...$keys): array
    {
        $trail = [
            ['label' => 'ImmoBase', 'url' => $this->urls->generate('app_dashboard')],
            [
                'label' => $this->translator->trans('finance.heading'),
                'url' => [] === $keys ? null : $this->urls->generate('app_finance'),
            ],
        ];

        foreach ($keys as $key) {
            $trail[] = ['label' => $this->translator->trans($key), 'url' => null];
        }

        return $trail;
    }
}
