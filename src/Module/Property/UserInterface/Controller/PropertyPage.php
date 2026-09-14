<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Property\UserInterface\Controller;

use App\Module\Property\Domain\Property;
use LogicException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Brotkrumen, Seitenadressen und die Abschnitte der Objektseite.
 *
 * Getrennt vom Controller, damit der bei den Aktionen bleibt.
 */
final readonly class PropertyPage
{
    public const string UNITS = 'einheiten';
    public const string BASICS = 'objektdaten';
    public const string ADDRESS = 'anschrift';
    public const string BUILDING = 'gebaeude';
    public const string HEATING = 'heizung';
    public const string REGISTRY = 'grundbuch';
    public const string ACCOUNTING = 'abrechnung';
    public const string BANK = 'bankkonto';
    public const string MEA = 'anteile';

    /**
     * Der Schluessel in der Adresszeile ist deutsch, der in den Uebersetzungen
     * englisch. Hier laufen sie zusammen.
     */
    private const array NAMES = [
        self::BASICS => 'basics',
        self::UNITS => 'units',
        self::ADDRESS => 'address',
        self::BUILDING => 'building',
        self::HEATING => 'heating',
        self::REGISTRY => 'registry',
        self::ACCOUNTING => 'accounting',
        self::BANK => 'bank',
        self::MEA => 'mea',
    ];

    public function __construct(
        private UrlGeneratorInterface $urls,
        private TranslatorInterface $translator,
    ) {
    }

    /**
     * Die Abschnitte eines Objekts.
     *
     * „Anteile" entfaellt bei reiner Mietverwaltung: ein Abschnitt, der nichts
     * bedeutet, ist schlimmer als keiner.
     *
     * @return list<string>
     */
    public static function keys(Property $property): array
    {
        $keys = [
            self::UNITS, self::BASICS, self::ADDRESS,
            self::BUILDING, self::HEATING, self::REGISTRY, self::ACCOUNTING, self::BANK,
        ];

        return $property->modes()->needMea() ? [...$keys, self::MEA] : $keys;
    }

    /**
     * Ein unbekannter Abschnitt aus der Adresszeile faellt auf den ersten
     * zurueck — Eingabe, kein Programmierfehler.
     */
    public static function known(string $requested, Property $property): string
    {
        return \in_array($requested, self::keys($property), true) ? $requested : self::UNITS;
    }

    /**
     * @return array<string, mixed>
     */
    public function sections(Property $property, string $current): array
    {
        return [
            'current' => $current,
            'sections' => array_map(fn (string $key): array => [
                'key' => $key,
                'label' => $this->translator->trans('property.section.'.self::name($key)),
                'url' => $this->urls->generate('app_property_show', [
                    'number' => $property->number(),
                    'abschnitt' => $key,
                ]),
            ], self::keys($property)),
            'heading' => $property->name(),
            'subheading' => $this->translator->trans('property.field.number').' '.$property->number(),
            'title' => $this->translator->trans('property.title.'.self::name($current)),
            'explanation' => $this->translator->trans('property.explanation.'.self::name($current)),
        ];
    }

    /**
     * Die Adresse der Liste mit __PAGE__ fuer die Seitenzahl.
     *
     * Die Filter muessen mitwandern: eine Seite zwei ohne Filter zeigt etwas
     * anderes als Seite eins mit Filter.
     */
    public function listUrl(Request $request): string
    {
        $parameters = array_filter([
            'q' => $request->query->getString('q'),
            'art' => $request->query->getString('art'),
            'status' => $request->query->getString('status'),
        ], static fn (string $value): bool => '' !== $value);

        return $this->urls->generate('app_property', [...$parameters, 'page' => '__PAGE__']);
    }

    /**
     * @return list<array{label: string, url: string|null}>
     */
    public function trail(?Property $property, ?string $leaf = null): array
    {
        $trail = [
            ['label' => 'ImmoBase', 'url' => $this->urls->generate('app_dashboard')],
            ['label' => $this->translator->trans('property.heading'), 'url' => null === $property
                ? null
                : $this->urls->generate('app_property')],
        ];

        if (null !== $property) {
            $trail[] = [
                'label' => $property->name(),
                'url' => null === $leaf ? null : $this->urls->generate('app_property_show', [
                    'number' => $property->number(),
                ]),
            ];
        }

        if (null !== $leaf) {
            $trail[] = ['label' => $leaf, 'url' => null];
        }

        return $trail;
    }

    /** Ein Abschnitt ohne Namen ist ein vergessener Eintrag; siehe known(). */
    private static function name(string $key): string
    {
        return self::NAMES[$key] ?? throw new LogicException('Der Abschnitt '.$key.' hat keinen Namen.');
    }
}
