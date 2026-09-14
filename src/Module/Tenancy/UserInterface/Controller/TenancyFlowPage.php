<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Tenancy\UserInterface\Controller;

use App\Module\Party\Contract\PartyBrief;
use App\Module\Party\Contract\PartyDirectory;
use App\Module\Property\Contract\UnitBrief;
use App\Module\Property\Contract\UnitDirectory;
use App\Module\Tenancy\Domain\Tenancy;
use App\Shared\Text\Trimmed;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Was ein Schritt zum Zeichnen braucht.
 *
 * Die Schrittliste links traegt Adressen und keine Formularknoepfe: jeder
 * Schritt hat schon gespeichert, also geht kein Getipptes verloren, wenn
 * jemand springt.
 */
final readonly class TenancyFlowPage
{
    public function __construct(
        private UrlGeneratorInterface $urls,
        private TranslatorInterface $translator,
        private UnitDirectory $units,
        private PartyDirectory $parties,
    ) {
    }

    /**
     * @param array<string, string> $errors
     *
     * @return array<string, mixed>
     */
    public function parameters(?Tenancy $tenancy, string $step, array $errors): array
    {
        $heading = null === $tenancy ? 'tenancy.new.heading' : 'tenancy.edit.heading';

        return [
            'tenancy' => $tenancy,
            'step' => $step,
            'errors' => $errors,
            'action' => $this->actionFor($tenancy, $step),
            'sections' => $this->steps($tenancy),
            'current' => $step,
            'heading' => null === $tenancy
                ? $this->translator->trans($heading)
                : $this->translator->trans('tenancy.number', ['%number%' => $tenancy->number()]),
            'subheading' => null === $tenancy ? $this->translator->trans('tenancy.new.explanation') : null,
            'title' => $this->translator->trans('tenancy.section.'.TenancyFlow::name($step)),
            'explanation' => $this->translator->trans('tenancy.explanation.'.TenancyFlow::name($step)),
            ...$this->place($tenancy, $step),
            'trail' => [
                ['label' => 'ImmoBase', 'url' => $this->urls->generate('app_dashboard')],
                ['label' => $this->translator->trans('tenancy.heading'), 'url' => $this->urls->generate('app_tenancy')],
                ['label' => $this->translator->trans($heading), 'url' => null],
            ],
        ];
    }

    /**
     * Die Einheit, die im Feld stehen soll.
     *
     * Nach einem Fehler die abgeschickte und nicht die gespeicherte: wer sich
     * vergriffen hat, will die Stelle verbessern und nicht neu suchen.
     *
     * @param array<string, string> $errors
     */
    public function chosenUnit(?Tenancy $tenancy, Request $request, array $errors): ?UnitBrief
    {
        $id = [] === $errors
            ? $tenancy?->unitId()
            : Trimmed::orNull($request->request->getString('unitId'));

        return null === $id ? null : ($this->units->byIds([$id])[$id] ?? null);
    }

    /**
     * Dasselbe fuer die Mieter.
     *
     * @param array<string, string> $errors
     *
     * @return list<array{partyId: string, brief: PartyBrief|null}>
     */
    public function chosenTenants(?Tenancy $tenancy, Request $request, array $errors): array
    {
        $ids = [] === $errors
            ? array_map(static fn (object $tenant): string => $tenant->partyId(), $tenancy?->tenants() ?? [])
            : TenancyStepInput::tenantsFrom($request);

        // Ein gerade angelegter Kontakt kommt als `?neu=` zurueck. Er steht
        // dann gleich in der Liste — wer mitten im Ablauf einen Mieter
        // anlegt, soll ihn beim Zurueckkommen nicht noch einmal suchen.
        $fresh = Trimmed::orNull($request->query->getString('neu'));

        if (null !== $fresh && !\in_array($fresh, $ids, true)) {
            $ids[] = $fresh;
        }

        $briefs = $this->parties->byIds(array_values($ids));

        return array_map(static fn (string $id): array => [
            'partyId' => $id,
            'brief' => $briefs[$id] ?? null,
        ], array_values($ids));
    }

    /**
     * Wo im Ablauf man steht und wohin es von hier geht.
     *
     * @return array<string, mixed>
     */
    private function place(?Tenancy $tenancy, string $step): array
    {
        return [
            'position' => $this->translator->trans('flow.step_position', [
                '%position%' => TenancyFlow::positionOf($step),
                '%count%' => TenancyFlow::count(),
            ]),
            'isLast' => null !== $tenancy && null === TenancyFlow::next($step),
            'hasPrevious' => null !== $tenancy && null !== TenancyFlow::previous($step),
            'cancel' => null === $tenancy
                ? $this->urls->generate('app_tenancy')
                : $this->urls->generate('app_tenancy_show', ['number' => $tenancy->number()]),
        ];
    }

    /**
     * Beim Anlegen ist nur der erste Schritt erreichbar — die uebrigen stehen
     * blass daneben, damit sichtbar ist, was folgt.
     *
     * @return list<array{key: string, label: string, url: string|null}>
     */
    private function steps(?Tenancy $tenancy): array
    {
        return array_map(fn (string $key): array => [
            'key' => $key,
            'label' => $this->translator->trans('tenancy.section.'.TenancyFlow::name($key)),
            'url' => null === $tenancy ? null : $this->urls->generate('app_tenancy_edit', [
                'number' => $tenancy->number(),
                'step' => $key,
            ]),
        ], TenancyFlow::keys());
    }

    private function actionFor(?Tenancy $tenancy, string $step): string
    {
        return null === $tenancy
            ? $this->urls->generate('app_tenancy_new')
            : $this->urls->generate('app_tenancy_edit', [
                'number' => $tenancy->number(),
                'step' => $step,
            ]);
    }
}
