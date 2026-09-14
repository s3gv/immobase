<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Dunning\UserInterface\Controller;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Die Brotkrumen und die Listenadresse des Mahnwesens.
 *
 * Unter den Finanzen, weil die Seite dort haengt: wer mahnt, kam ueber die
 * Zahlungen dorthin.
 */
final readonly class DunningPage
{
    public function __construct(
        private UrlGeneratorInterface $urls,
        private TranslatorInterface $translator,
    ) {
    }

    /** Die Adresse der Liste mit Platzhalter fuer die Seitenzahl. */
    public function listUrl(Request $request): string
    {
        $parameters = [];

        foreach (['objekt', 'zustand', 'q'] as $name) {
            $value = trim($request->query->getString($name));

            if ('' !== $value) {
                $parameters[$name] = $value;
            }
        }

        return $this->urls->generate('app_dunning', [...$parameters, 'page' => '__PAGE__']);
    }

    /**
     * @param string ...$keys Uebersetzungsschluessel der Unterseiten
     *
     * @return list<array{label: string, url: string|null}>
     */
    public function trail(string ...$keys): array
    {
        $trail = [
            ['label' => 'ImmoBase', 'url' => $this->urls->generate('app_dashboard')],
            ['label' => $this->translator->trans('finance.heading'), 'url' => $this->urls->generate('app_finance')],
            [
                'label' => $this->translator->trans('dunning.heading'),
                'url' => [] === $keys ? null : $this->urls->generate('app_dunning'),
            ],
        ];

        foreach ($keys as $key) {
            $trail[] = ['label' => $this->translator->trans($key), 'url' => null];
        }

        return $trail;
    }
}
