<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Tenancy\UserInterface\Controller;

use App\Module\Tenancy\Domain\Tenancy;
use App\Shared\Ui\Sort;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Brotkrumen, Seitenadressen und die Abschnitte der Mietseite.
 *
 * Getrennt vom Controller, damit der bei den Aktionen bleibt.
 */
final readonly class TenancyPage
{
    public function __construct(
        private UrlGeneratorInterface $urls,
        private TranslatorInterface $translator,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function sections(Tenancy $tenancy, string $current): array
    {
        return [
            'current' => $current,
            'sections' => array_map(fn (string $key): array => [
                'key' => $key,
                'label' => $this->translator->trans('tenancy.section.'.TenancyFlow::name($key)),
                'url' => $this->urls->generate('app_tenancy_show', [
                    'number' => $tenancy->number(),
                    'abschnitt' => $key,
                ]),
            ], TenancyFlow::keys()),
            'heading' => $this->translator->trans('tenancy.number', ['%number%' => $tenancy->number()]),
            'title' => $this->translator->trans('tenancy.section.'.TenancyFlow::name($current)),
            'explanation' => $this->translator->trans('tenancy.explanation.'.TenancyFlow::name($current)),
        ];
    }

    /**
     * Die Adresse der Liste mit __PAGE__ fuer die Seitenzahl.
     *
     * Filter und Sortierung muessen mitwandern: eine Seite zwei ohne Filter
     * zeigt etwas anderes als Seite eins mit Filter, und eine Sortierung, die
     * beim Blaettern verlorengeht, ist keine.
     */
    public function listUrl(Request $request): string
    {
        return $this->urls->generate('app_tenancy', [...self::query($request), 'page' => '__PAGE__']);
    }

    /**
     * Je sortierbarer Spalte die Adresse, die ein Klick ergibt.
     *
     * Fertig gebaut und nicht in der Vorlage zusammengesetzt: nur hier ist
     * bekannt, welche Filter mitgehen muessen.
     *
     * @param list<string> $fields
     *
     * @return array<string, string>
     */
    public function sortUrls(Request $request, Sort $sort, array $fields): array
    {
        $urls = [];

        foreach ($fields as $field) {
            $urls[$field] = $this->urls->generate('app_tenancy', [
                ...self::query($request),
                'sortieren' => $field,
                'richtung' => $sort->nextDirectionFor($field),
            ]);
        }

        return $urls;
    }

    /**
     * @return list<array{label: string, url: string|null}>
     */
    public function trail(?Tenancy $tenancy, ?string $leaf = null): array
    {
        $trail = [
            ['label' => 'ImmoBase', 'url' => $this->urls->generate('app_dashboard')],
            ['label' => $this->translator->trans('tenancy.heading'), 'url' => null === $tenancy && null === $leaf
                ? null
                : $this->urls->generate('app_tenancy')],
        ];

        if (null !== $tenancy) {
            $trail[] = [
                'label' => $this->translator->trans('tenancy.number', ['%number%' => $tenancy->number()]),
                'url' => null === $leaf ? null : $this->urls->generate('app_tenancy_show', [
                    'number' => $tenancy->number(),
                ]),
            ];
        }

        if (null !== $leaf) {
            $trail[] = ['label' => $leaf, 'url' => null];
        }

        return $trail;
    }

    /**
     * Filter und Sortierung aus der Adresszeile, leere weggelassen.
     *
     * @return array<string, string>
     */
    private static function query(Request $request): array
    {
        return array_filter([
            'q' => $request->query->getString('q'),
            'objekt' => $request->query->getString('objekt'),
            'status' => $request->query->getString('status'),
            'sortieren' => $request->query->getString('sortieren'),
            'richtung' => $request->query->getString('richtung'),
        ], static fn (string $value): bool => '' !== $value);
    }
}
