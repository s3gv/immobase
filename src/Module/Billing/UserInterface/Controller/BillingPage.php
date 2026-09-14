<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Billing\UserInterface\Controller;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Die Brotkrumen der Abrechnungsseiten.
 *
 * Als eigener Dienst, damit die Controller nicht uebersetzen: sie
 * entscheiden, was passiert, nicht wie es heisst.
 */
final readonly class BillingPage
{
    public function __construct(
        private UrlGeneratorInterface $urls,
        private TranslatorInterface $translator,
    ) {
    }

    /**
     * Die Adresse der Liste mit Platzhalter fuer die Seitenzahl.
     *
     * Die Filter kommen mit: wer auf Seite zwei blaettert, will dieselbe
     * Liste sehen und nicht wieder alle.
     */
    public function listUrl(Request $request): string
    {
        $parameters = [];

        foreach (['objekt', 'jahr', 'zustand', 'q'] as $name) {
            $value = trim($request->query->getString($name));

            if ('' !== $value) {
                $parameters[$name] = $value;
            }
        }

        return $this->urls->generate('app_billing_statement', [...$parameters, 'page' => '__PAGE__']);
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
            [
                'label' => $this->translator->trans('billing.heading'),
                'url' => [] === $keys ? null : $this->urls->generate('app_billing'),
            ],
        ];

        foreach ($keys as $key) {
            $trail[] = ['label' => $this->translator->trans($key), 'url' => null];
        }

        return $trail;
    }
}
