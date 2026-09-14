<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Tenancy\Application;

use App\Module\Tenancy\Contract\TenancyLinkSource;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * Sammelt, was an einem Mietverhaeltnis haengt.
 *
 * Fragt jedes Modul, das sich als Quelle angemeldet hat. Heute gibt es keine
 * — dann ist die Antwort leer, und geloescht werden darf. Beim ersten
 * Zahlungseingang aendert sich das von selbst, ohne dass hier jemand etwas
 * nachziehen muesste.
 */
final readonly class CountTenancyLinks
{
    /**
     * @param iterable<TenancyLinkSource> $sources
     */
    public function __construct(
        #[AutowireIterator('tenancy.link_source')]
        private iterable $sources,
    ) {
    }

    /**
     * @param list<string> $tenancyIds
     *
     * @return array<string, list<string>>
     */
    public function forTenancies(array $tenancyIds): array
    {
        $links = [];

        foreach ($this->sources as $source) {
            foreach ($source->linksTo($tenancyIds) as $tenancyId => $found) {
                $links[$tenancyId] = array_values(array_unique([...$links[$tenancyId] ?? [], ...$found]));
            }
        }

        return $links;
    }

    /**
     * @return list<string>
     */
    public function forTenancy(string $tenancyId): array
    {
        return $this->forTenancies([$tenancyId])[$tenancyId] ?? [];
    }

    public function anyFor(string $tenancyId): bool
    {
        return [] !== $this->forTenancy($tenancyId);
    }
}
