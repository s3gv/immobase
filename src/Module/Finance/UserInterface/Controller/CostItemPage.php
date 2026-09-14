<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Finance\UserInterface\Controller;

use App\Module\Finance\Domain\CostItem;
use App\Shared\Ui\Sort;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Die Abschnitte der Kostenseite.
 *
 * Dieselben wie im Ablauf, nur ist hier nichts ein Weg: man springt, statt
 * weiterzugehen. Wer das Anlegen kennt, findet sich zurecht.
 */
final readonly class CostItemPage
{
    public function __construct(
        private UrlGeneratorInterface $urls,
        private TranslatorInterface $translator,
    ) {
    }

    /**
     * Ein unbekannter Abschnitt aus der Adresszeile faellt auf den ersten
     * zurueck — Eingabe, kein Programmierfehler.
     */
    public static function known(string $requested): string
    {
        return CostItemFlow::known($requested);
    }

    /**
     * @return array<string, mixed>
     */
    public function sections(CostItem $item, string $current): array
    {
        return [
            'current' => $current,
            'sections' => array_map(fn (string $key): array => [
                'key' => $key,
                'label' => $this->translator->trans('finance.item.section.'.CostItemFlow::name($key)),
                'url' => $this->urls->generate('app_finance_item_show', [
                    'number' => $item->number(),
                    'abschnitt' => $key,
                ]),
            ], CostItemFlow::keys()),
            'heading' => $this->translator->trans('finance.item.number', ['%number%' => $item->number()]),
            'subheading' => $item->kind()->name(),
            'title' => $this->translator->trans('finance.item.section.'.CostItemFlow::name($current)),
            'explanation' => $this->translator->trans('finance.item.explanation.'.CostItemFlow::name($current)),
            'trail' => [
                ['label' => 'ImmoBase', 'url' => $this->urls->generate('app_dashboard')],
                [
                    'label' => $this->translator->trans('finance.item.heading'),
                    'url' => $this->urls->generate('app_finance_item'),
                ],
                [
                    'label' => $this->translator->trans('finance.item.number', ['%number%' => $item->number()]),
                    'url' => null,
                ],
            ],
        ];
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
            $urls[$field] = $this->urls->generate('app_finance_item', [
                ...self::query($request),
                'sortieren' => $field,
                'richtung' => $sort->nextDirectionFor($field),
            ]);
        }

        return $urls;
    }

    /**
     * Die Adresse der Liste mit __PAGE__ fuer die Seitenzahl.
     *
     * Die Filter muessen mitwandern: eine Seite zwei ohne Filter zeigt etwas
     * anderes als Seite eins mit Filter.
     */
    public function listUrl(Request $request): string
    {
        return $this->urls->generate('app_finance_item', [...self::query($request), 'page' => '__PAGE__']);
    }

    /**
     * Die Filter der Adresszeile, leere weggelassen.
     *
     * @return array<string, string>
     */
    private static function query(Request $request): array
    {
        $parameters = [];

        foreach (['objekt', 'art', 'umlage', 'q', 'sortieren', 'richtung'] as $name) {
            $value = trim($request->query->getString($name));

            if ('' !== $value) {
                $parameters[$name] = $value;
            }
        }

        return $parameters;
    }
}
