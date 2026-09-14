<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Dunning\UserInterface\Twig;

use App\Module\Dunning\Contract\DunningOverview;
use App\Module\Dunning\Contract\DunningPressure;
use App\Module\Dunning\Domain\DunningPermissions;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * `{{ dunning_pressure() }}` fuer Seitenleiste, Finanzen und Dashboard.
 *
 * **Die Vorlagen fragen, nicht die Module.** Die Finanzen duerfen das
 * Mahnwesen nicht kennen — sie sind die Quelle, und ein Aufruf zurueck waere
 * genau der Zyklus, den `AdvanceDirectory` vermeidet. Zusammengesetzt wird in
 * der Ansicht, und dort gehoert das Zusammensetzen hin.
 *
 * **Gezaehlt wird bei jedem Aufruf**, anders als beim Abzeichen der
 * Abrechnungen. Dort muss jede freigegebene Abrechnung neu gerechnet werden;
 * hier sind es zwei zaehlende Abfragen ueber indizierte Spalten. Das kostet
 * weniger, als es kostete, die Zahl zu merken und drei Auffrischungspunkte
 * dafuer zu pflegen.
 *
 * Gezaehlt wird nur fuer Konten, die das Mahnwesen sehen duerfen — fuer alle
 * anderen waere es Arbeit fuer eine Zahl, die nirgends erscheint.
 */
final class DunningBadge extends AbstractExtension
{
    public function __construct(
        private readonly DunningOverview $dunning,
        private readonly AuthorizationCheckerInterface $mayView,
    ) {
    }

    public function getFunctions(): array
    {
        return [new TwigFunction('dunning_pressure', $this->pressure(...))];
    }

    public function pressure(): DunningPressure
    {
        return $this->mayView->isGranted(DunningPermissions::VIEW)
            ? $this->dunning->pressure()
            : DunningPressure::nothing();
    }
}
