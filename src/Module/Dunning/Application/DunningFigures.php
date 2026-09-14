<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Dunning\Application;

use App\Module\Dunning\Contract\DunningOverview;
use App\Module\Dunning\Domain\DunningPermissions;
use App\Shared\Figure\ContributesFigures;
use App\Shared\Figure\Figure;
use App\Shared\Figure\FigureGroup;
use App\Shared\Locale\CurrentLocale;
use App\Shared\Money\MoneyFormatter;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

/**
 * Die Zahl, mit der man morgens anfaengt: was offen ist.
 *
 * Dieselbe Summe wie auf der Mahnwesen-Uebersicht und in der Tafel der
 * Finanzen — aus derselben Quelle, damit nirgends zwei Zahlen fuer dieselbe
 * Sache stehen.
 */
#[AsTaggedItem(priority: 100)]
final readonly class DunningFigures implements ContributesFigures
{
    public function __construct(
        private DunningOverview $dunning,
        private CurrentLocale $locale,
        private AuthorizationCheckerInterface $mayView,
        private UrlGeneratorInterface $urls,
    ) {
    }

    public function figures(): array
    {
        if (!$this->mayView->isGranted(DunningPermissions::VIEW)) {
            return [];
        }

        $open = $this->dunning->pressure()->open;

        return [new Figure(
            group: FigureGroup::Money,
            labelKey: 'figure.dunning.open',
            value: MoneyFormatter::format($open, $this->locale->code()),
            tone: $open->isZero() ? 'success' : 'danger',
            url: $this->urls->generate('app_dunning'),
        )];
    }
}
