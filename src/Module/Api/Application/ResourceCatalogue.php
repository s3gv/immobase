<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Api\Application;

use App\Shared\Api\PublishesResource;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * Was es unter `/api/v1/` gibt.
 *
 * Eingesammelt und nicht aufgezaehlt: eine Liste hier waere eine zweite
 * Wahrheit neben den Modulen, und sie waere schon beim naechsten Modul
 * unvollstaendig.
 */
final readonly class ResourceCatalogue
{
    /**
     * @param iterable<PublishesResource> $resources
     */
    public function __construct(
        #[AutowireIterator('api.resource')]
        private iterable $resources,
    ) {
    }

    public function named(string $name): ?PublishesResource
    {
        foreach ($this->resources as $resource) {
            if ($resource->name() === $name) {
                return $resource;
            }
        }

        return null;
    }

    /**
     * @return list<PublishesResource>
     */
    public function all(): array
    {
        return array_values([...$this->resources]);
    }
}
