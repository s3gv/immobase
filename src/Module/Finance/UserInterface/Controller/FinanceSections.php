<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Finance\UserInterface\Controller;

use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Der Rahmen einer Seite in Abschnitten.
 *
 * Dieselbe Gestalt wie ein Ablauf: links die Abschnitte, rechts einer davon.
 * Es ist keiner — dort fuehrt ein Weg von vorn nach hinten, hier steht alles
 * nebeneinander und jeder Abschnitt speichert fuer sich. Gemeinsam ist die
 * Gestalt, und darum geht es: wer ein Formular in ImmoBase kennt, kennt alle.
 *
 * Als eigener Dienst, weil drei Seiten dasselbe brauchen und die Controller
 * sonst zur Haelfte aus Beschriftungen bestuenden.
 */
final readonly class FinanceSections
{
    public function __construct(
        private UrlGeneratorInterface $urls,
        private TranslatorInterface $translator,
    ) {
    }

    /**
     * Was die Vorlage zum Zeichnen der Abschnittsliste braucht.
     *
     * Was aus der Adresszeile kommt, ist Eingabe: ein unbekannter Abschnitt
     * ist kein Fehler, sondern eine Angabe, die es so nicht gibt — dann steht
     * der erste da.
     *
     * @param array<string, string|int> $parameters was die Route sonst braucht
     * @param non-empty-list<string>    $keys       Abschnitte in ihrer Reihenfolge
     *
     * @return array{sections: list<array{key: string, label: string, url: string|null}>, current: string, title: string, explanation: string}
     */
    public function frame(string $route, array $parameters, string $prefix, array $keys, string $chosen): array
    {
        $current = \in_array($chosen, $keys, true) ? $chosen : $keys[0];

        return [
            'sections' => array_map(fn (string $key): array => [
                'key' => $key,
                'label' => $this->translator->trans($prefix.'.section.'.$key),
                'url' => $this->urls->generate($route, [...$parameters, 'abschnitt' => $key]),
            ], $keys),
            'current' => $current,
            'title' => $this->translator->trans($prefix.'.section.'.$current),
            'explanation' => $this->translator->trans($prefix.'.section_explanation.'.$current),
        ];
    }
}
