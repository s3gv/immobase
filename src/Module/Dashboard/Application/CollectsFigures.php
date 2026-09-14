<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Dashboard\Application;

use App\Module\Dashboard\Contract\FigureSection;
use App\Shared\Figure\ContributesFigures;
use App\Shared\Figure\Figure;
use App\Shared\Figure\FigureGroup;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * Die Zahlen des Morgens, aus allen Modulen.
 *
 * Wie {@see CollectsTodos}: sie kennt kein Fachmodul und prueft keine Rechte
 * — beides tut die Quelle. Hier wird nur gruppiert und geordnet.
 */
final readonly class CollectsFigures
{
    /**
     * @param iterable<ContributesFigures> $sources
     */
    public function __construct(
        #[AutowireIterator('figure.source')]
        private iterable $sources,
    ) {
    }

    /**
     * @return list<FigureSection> nur Gruppen, in denen etwas steht
     */
    public function sections(): array
    {
        $byGroup = [];

        foreach ($this->sources as $source) {
            foreach ($source->figures() as $figure) {
                $byGroup[$figure->group->value][] = $figure;
            }
        }

        $sections = [];

        foreach (FigureGroup::inOrder() as $group) {
            /** @var list<Figure> $figures */
            $figures = $byGroup[$group->value] ?? [];

            if ([] !== $figures) {
                $sections[] = new FigureSection($group, $figures);
            }
        }

        return $sections;
    }
}
