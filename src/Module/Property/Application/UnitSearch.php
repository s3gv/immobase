<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Property\Application;

use App\Module\Property\Domain\PropertyPermissions;
use App\Module\Property\Domain\Unit;
use App\Module\Property\Domain\UnitRepository;
use App\Shared\Search\SearchesRecords;
use App\Shared\Search\SearchHit;
use App\Shared\Search\SearchTerm;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

/**
 * Die Einheiten in der zentralen Suche — eine eigene Art neben den Objekten.
 *
 * Eigene Quelle und nicht ein zweiter Rueckgabewert der Objektsuche: eine
 * Quelle liefert eine Art, sonst wuesste „Alle anzeigen" nicht, wohin.
 *
 * Einheiten haben keine eigene Liste, in der sich suchen liesse — sie stehen
 * unter ihrem Objekt. Darum bleibt der Weg dorthin leer statt auf eine Liste
 * zu fuehren, die die Suche vergisst.
 */
#[AsTaggedItem(priority: 90)]
final readonly class UnitSearch implements SearchesRecords
{
    public function __construct(
        private UnitRepository $units,
        private AuthorizationCheckerInterface $mayView,
        private UrlGeneratorInterface $urls,
    ) {
    }

    public function kindKey(): string
    {
        return 'search.kind.unit';
    }

    public function listUrl(SearchTerm $term): string
    {
        return '';
    }

    public function matching(SearchTerm $term, int $limit): array
    {
        if (!$this->mayView->isGranted(PropertyPermissions::VIEW)) {
            return [];
        }

        return array_map(
            fn (Unit $unit): SearchHit => new SearchHit(
                title: $unit->label(),
                subtitle: $unit->property()->name().' · '.$unit->property()->address()->oneLine(),
                reference: $unit->property()->number().'/'.$unit->number(),
                url: $this->urls->generate('app_unit_show', [
                    'number' => $unit->property()->number(),
                    'unit' => $unit->number(),
                ]),
            ),
            $this->units->anywhere($term, $limit),
        );
    }
}
