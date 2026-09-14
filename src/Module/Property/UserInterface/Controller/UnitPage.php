<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Property\UserInterface\Controller;

use App\Module\Property\Domain\Unit;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Was die Seite einer Einheit zum Zeichnen braucht.
 */
final readonly class UnitPage
{
    public function __construct(
        private UrlGeneratorInterface $urls,
        private TranslatorInterface $translator,
        private PropertyPage $properties,
    ) {
    }

    /**
     * @param list<string> $keys
     *
     * @return array<string, mixed>
     */
    public function frame(Unit $unit, string $current, array $keys, string $route): array
    {
        return [
            'unit' => $unit,
            'property' => $unit->property(),
            'current' => $current,
            'sections' => array_map(fn (string $key): array => [
                'key' => $key,
                'label' => $this->translator->trans('property.unit.section.'.self::name($key)),
                'url' => $this->urls->generate($route, [
                    'number' => $unit->property()->number(),
                    'unit' => $unit->number(),
                    ...('app_unit_edit' === $route ? ['step' => $key] : ['abschnitt' => $key]),
                ]),
            ], $keys),
            'heading' => $unit->label(),
            'subheading' => $this->translator->trans('property.unit.field.number').' '.$unit->number()
                .' · '.$unit->property()->name(),
            'title' => $this->translator->trans('property.unit.section.'.self::name($current)),
            'explanation' => $this->translator->trans('property.unit.explanation.'.self::name($current)),
            'trail' => $this->properties->trail($unit->property(), $unit->label()),
        ];
    }

    private static function name(string $key): string
    {
        return match ($key) {
            UnitFlow::OWNERS => 'owners',
            UnitFlow::DETAIL => 'detail',
            default => 'basics',
        };
    }
}
