<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Finance\Application;

use App\Module\Finance\Domain\HouseMoneyRepository;
use App\Module\Property\Contract\UnitLink;
use App\Module\Property\Contract\UnitLinkSource;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Meldet dem Objektmodul, was die Finanzen an einer Einheit haben.
 *
 * Die zweite Umsetzung von UnitLinkSource. Sie bewirkt dasselbe wie die
 * erste: auf der Einheitenseite steht ein Sprung zu den Vorauszahlungen, und
 * eine Einheit mit vereinbartem Hausgeld laesst sich nicht mehr loeschen —
 * sie wird abgegeben.
 *
 * Verbrauchswerte und Ruecklagenbuchungen haengen ebenfalls an Einheiten;
 * die halten schon die Fremdschluessel mit RESTRICT. Hier steht, was zum
 * Anspringen taugt.
 */
final readonly class FinancedUnits implements UnitLinkSource
{
    public function __construct(
        private HouseMoneyRepository $steps,
        private UrlGeneratorInterface $urls,
        private TranslatorInterface $translator,
    ) {
    }

    public function linksTo(array $unitIds): array
    {
        $links = [];

        foreach ($this->steps->forUnits($unitIds) as $unitId => $schedule) {
            if ($schedule->isEmpty()) {
                continue;
            }

            $links[$unitId] = [new UnitLink(
                labelKey: 'finance.advance.house_money',
                text: $this->translator->trans('finance.advance.steps', [
                    '%count%' => \count($schedule->steps()),
                ]),
                url: $this->urls->generate('app_finance_advance'),
                countKey: 'finance.advance.count',
            )];
        }

        return $links;
    }
}
