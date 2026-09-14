<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Property\Application;

use App\Module\Property\Domain\Property;
use App\Module\Property\Domain\PropertyPermissions;
use App\Module\Property\Domain\PropertyRepository;
use App\Shared\Search\SearchesRecords;
use App\Shared\Search\SearchHit;
use App\Shared\Search\SearchTerm;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

/**
 * Die Objekte in der zentralen Suche.
 *
 * Ueber Name, Nummer, Anschrift — und ueber die Bankverbindung: wer einen
 * Kontoauszug vor sich hat, hat die IBAN und nicht die Objektnummer.
 */
#[AsTaggedItem(priority: 100)]
final readonly class PropertySearch implements SearchesRecords
{
    public function __construct(
        private PropertyRepository $properties,
        private AuthorizationCheckerInterface $mayView,
        private UrlGeneratorInterface $urls,
    ) {
    }

    public function kindKey(): string
    {
        return 'search.kind.property';
    }

    public function listUrl(SearchTerm $term): string
    {
        return $this->urls->generate('app_property', ['q' => $term->raw]);
    }

    public function matching(SearchTerm $term, int $limit): array
    {
        if (!$this->mayView->isGranted(PropertyPermissions::VIEW)) {
            return [];
        }

        return array_map(
            fn (Property $property): SearchHit => new SearchHit(
                title: $property->name(),
                subtitle: $property->address()->oneLine(),
                reference: (string) $property->number(),
                url: $this->urls->generate('app_property_show', ['number' => $property->number()]),
            ),
            $this->properties->anywhere($term, $limit),
        );
    }
}
