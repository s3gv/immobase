<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Tenancy\Application;

use App\Module\Tenancy\Domain\TenancyFilter;
use App\Module\Tenancy\Domain\TenancyPermissions;
use App\Module\Tenancy\Domain\TenancyRepository;
use App\Module\Tenancy\Domain\TenancyStatus;
use App\Shared\Figure\ContributesFigures;
use App\Shared\Figure\Figure;
use App\Shared\Figure\FigureGroup;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

/**
 * Wie viele Einheiten gerade vermietet sind.
 *
 * **Und nicht „Leerstand".** Der waere die Differenz zu allen Einheiten — und
 * die stimmte nur dort, wo jede Einheit vermietet werden soll. In einer WEG
 * gehoert die Wohnung dem Eigentuemer, der darin wohnt; sie stuende dann als
 * Leerstand da, und die Zahl waere jeden Tag falsch.
 */
#[AsTaggedItem(priority: 80)]
final readonly class TenancyFigures implements ContributesFigures
{
    public function __construct(
        private TenancyRepository $tenancies,
        private AuthorizationCheckerInterface $mayView,
        private UrlGeneratorInterface $urls,
    ) {
    }

    public function figures(): array
    {
        if (!$this->mayView->isGranted(TenancyPermissions::VIEW)) {
            return [];
        }

        return [new Figure(
            group: FigureGroup::Stock,
            labelKey: 'figure.tenancy.active',
            value: (string) $this->tenancies->countMatching(TenancyFilter::of(TenancyStatus::Active->value)),
            url: $this->urls->generate('app_tenancy', ['status' => TenancyStatus::Active->value]),
        )];
    }
}
