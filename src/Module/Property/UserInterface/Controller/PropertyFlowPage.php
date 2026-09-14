<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Property\UserInterface\Controller;

use App\Module\Property\Domain\Property;
use LogicException;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Was ein Schritt zum Zeichnen braucht.
 *
 * Die Schrittliste links traegt Adressen und keine Formularknoepfe: jeder
 * Schritt hat schon gespeichert, also geht kein Getipptes verloren, wenn
 * jemand springt. Bei den Stammdaten ist das anders — dort haelt die Sitzung
 * den Zwischenstand, und ein Link waere ein Datenverlust.
 */
final readonly class PropertyFlowPage
{
    /**
     * Der Schluessel in der Adresszeile ist deutsch, der in den Uebersetzungen
     * englisch. Hier laufen sie zusammen.
     */
    private const array NAMES = [
        PropertyFlow::NAME => 'name',
        PropertyFlow::ADDRESS => 'address',
        PropertyFlow::BUILDING => 'building',
        PropertyFlow::HEATING => 'heating',
        PropertyFlow::REGISTRY => 'registry',
        PropertyFlow::ACCOUNTING => 'accounting',
        PropertyFlow::BANK => 'bank',
        PropertyFlow::MEA => 'mea',
        PropertyFlow::UNITS => 'units',
    ];

    public function __construct(
        private UrlGeneratorInterface $urls,
        private TranslatorInterface $translator,
    ) {
    }

    /**
     * @param array<string, string> $errors
     *
     * @return array<string, mixed>
     */
    public function parameters(?Property $property, string $step, array $errors): array
    {
        $heading = null === $property ? 'property.new.heading' : 'property.edit.heading';

        return [
            'property' => $property,
            'step' => $step,
            'errors' => $errors,
            'action' => $this->actionFor($property, $step),
            'sections' => $this->steps($property),
            'current' => $step,
            'heading' => null === $property
                ? $this->translator->trans($heading)
                : $property->name(),
            'subheading' => null === $property
                ? $this->translator->trans('property.new.explanation')
                : $this->translator->trans('property.field.number').' '.$property->number(),
            'title' => $this->translator->trans('property.step.'.self::name($step)),
            'explanation' => $this->translator->trans('property.explanation.'.self::name($step)),
            ...$this->place($property, $step),
            'trail' => [
                ['label' => 'ImmoBase', 'url' => $this->urls->generate('app_dashboard')],
                ['label' => $this->translator->trans('property.heading'), 'url' => $this->urls->generate('app_property')],
                ['label' => $this->translator->trans($heading), 'url' => null],
            ],
        ];
    }

    /**
     * Wo im Ablauf man steht und wohin es von hier geht.
     *
     * @return array<string, mixed>
     */
    private function place(?Property $property, string $step): array
    {
        return [
            'position' => $this->translator->trans('flow.step_position', [
                '%position%' => PropertyFlow::positionOf($step, $property),
                '%count%' => PropertyFlow::count($property),
            ]),
            'isLast' => null !== $property && PropertyFlow::isLast($step, $property),
            'hasPrevious' => null !== $property && null !== PropertyFlow::previous($step, $property),
            'cancel' => null === $property
                ? $this->urls->generate('app_property')
                : $this->urls->generate('app_property_show', ['number' => $property->number()]),
        ];
    }

    /**
     * Beim Anlegen ist nur der erste Schritt erreichbar — die uebrigen stehen
     * blass daneben, damit sichtbar ist, was folgt.
     *
     * @return list<array{key: string, label: string, url: string|null}>
     */
    private function steps(?Property $property): array
    {
        return array_map(fn (string $key): array => [
            'key' => $key,
            'label' => $this->translator->trans('property.step.'.self::name($key)),
            'url' => null === $property ? null : $this->urls->generate('app_property_edit', [
                'number' => $property->number(),
                'step' => $key,
            ]),
        ], PropertyFlow::keys($property));
    }

    private function actionFor(?Property $property, string $step): string
    {
        return null === $property
            ? $this->urls->generate('app_property_new')
            : $this->urls->generate('app_property_edit', [
                'number' => $property->number(),
                'step' => $step,
            ]);
    }

    /**
     * Ein Schritt ohne Namen ist ein vergessener Eintrag und kein Zustand,
     * den ein Benutzer erreichen kann: die Adresszeile laeuft vorher durch
     * {@see PropertyFlow::known()}. Ein stiller Rueckfall haette hier die
     * Beschriftung des ersten Schritts an einen neuen gehaengt.
     */
    private static function name(string $key): string
    {
        return self::NAMES[$key] ?? throw new LogicException('Der Schritt '.$key.' hat keinen Namen.');
    }
}
