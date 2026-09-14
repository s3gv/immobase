<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Dunning\UserInterface\Controller;

use App\Module\Dunning\Domain\Claim;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Was die Vorlage zum Zeichnen eines Erfassungsschrittes braucht.
 *
 * Getrennt vom Controller, damit der bei den Aktionen bleibt: er entscheidet,
 * was passiert, nicht wie es heisst.
 */
final readonly class ClaimFlowPage
{
    public function __construct(
        private UrlGeneratorInterface $urls,
        private TranslatorInterface $translator,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function frame(?Claim $claim, string $step): array
    {
        return [
            'sections' => array_map(
                fn (string $key): array => $this->section($claim, $key),
                ClaimFlow::keys(),
            ),
            'current' => $step,
            'title' => $this->translator->trans('dunning.claim_step.'.ClaimFlow::name($step)),
            'explanation' => $this->translator->trans('dunning.claim_explanation.'.ClaimFlow::name($step)),
            'position' => $this->translator->trans('flow.step_position', [
                '%position%' => ClaimFlow::positionOf($step),
                '%count%' => ClaimFlow::count(),
            ]),
            'heading' => $this->translator->trans('dunning.heading'),
            'subheading' => '' === ($claim?->subject() ?? '')
                ? $this->translator->trans('dunning.add')
                : $claim?->subject(),
            'hasPrevious' => ClaimFlow::mayGoBack($step),
            'isLast' => null === ClaimFlow::next($step),
            'action' => null === $claim
                ? $this->urls->generate('app_dunning_record')
                : $this->urls->generate('app_dunning_record_edit', ['id' => $claim->id(), 'step' => $step]),
            'cancel' => $this->urls->generate('app_dunning'),
        ];
    }

    /**
     * @return array{key: string, label: string, url: string|null}
     */
    private function section(?Claim $claim, string $key): array
    {
        return [
            'key' => $key,
            'label' => $this->translator->trans('dunning.claim_step.'.ClaimFlow::name($key)),
            // Der erste Schritt legt die Forderung an. Ist sie angelegt, gibt
            // es dorthin nichts zurueckzukehren — der Punkt bleibt sichtbar,
            // aber niemand landet auf einem Formular, das ein zweites Mal
            // anlegen wuerde.
            'url' => null === $claim || ClaimFlow::CLAIM === $key
                ? null
                : $this->urls->generate('app_dunning_record_edit', ['id' => $claim->id(), 'step' => $key]),
        ];
    }
}
