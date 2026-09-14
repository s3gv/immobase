<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Party\UserInterface\Controller;

use App\Module\Party\Application\PartyDraft;
use App\Module\Party\Domain\Party;
use App\Module\Party\Domain\PartyRole;
use App\Shared\Flow\FlowDefinition;
use App\Shared\Flow\FlowState;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Stellt zusammen, was ein Schritt zum Zeichnen braucht.
 *
 * Getrennt vom Controller, damit der sich auf das Fuehren durch den Ablauf
 * beschraenkt. Ueberschrift, Brotkrumen und Abbrechen-Ziel haengen alle daran,
 * ob ein Datensatz bearbeitet oder ein neuer angelegt wird — das einmal zu
 * entscheiden reicht.
 */
final readonly class PartyFlowPage
{
    public function __construct(
        private UrlGeneratorInterface $urls,
        private TranslatorInterface $translator,
    ) {
    }

    /**
     * @param array<string, string> $errors
     *
     * @return array<string, mixed>
     */
    public function parameters(FlowDefinition $definition, FlowState $state, ?Party $party, array $errors): array
    {
        $heading = null === $party ? 'party.new' : 'party.edit';
        $own = null === $party
            ? $this->urls->generate('app_party_new')
            : $this->urls->generate('app_party_edit', ['reference' => $party->reference()]);

        return [
            'definition' => $definition,
            'state' => $state,
            'step' => $definition->step($state->currentStepKey()),
            'errors' => $errors,
            'roles' => PartyRole::cases(),
            'draft' => new PartyDraft($state->allValues()),
            'action' => $own,
            'heading' => $heading,
            'reference' => $party?->reference(),
            'cancel' => null === $party
                ? $this->urls->generate('app_party')
                : $this->urls->generate('app_party_show', ['reference' => $party->reference()]),
            'trail' => [
                ['label' => 'ImmoBase', 'url' => $this->urls->generate('app_dashboard')],
                ['label' => $this->translator->trans('party.heading'), 'url' => $this->urls->generate('app_party')],
                ['label' => $this->translator->trans($heading), 'url' => null],
            ],
        ];
    }
}
