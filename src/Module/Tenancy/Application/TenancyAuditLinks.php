<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Tenancy\Application;

use App\Module\Tenancy\Domain\TenancyRepository;
use App\Shared\Audit\LinksToRecords;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Wohin ein protokolliertes Mietverhaeltnis fuehrt.
 */
final readonly class TenancyAuditLinks implements LinksToRecords
{
    public function __construct(
        private TenancyRepository $tenancies,
        private UrlGeneratorInterface $urls,
    ) {
    }

    public function handles(): array
    {
        return ['Tenancy'];
    }

    public function urlsFor(string $record, array $ids): array
    {
        $urls = [];

        foreach ($this->tenancies->byIds($ids) as $tenancy) {
            $urls[$tenancy->id()] = $this->urls->generate('app_tenancy_show', ['number' => $tenancy->number()]);
        }

        return $urls;
    }
}
