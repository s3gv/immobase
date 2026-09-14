<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Property\Application;

use App\Module\Property\Domain\PropertyFilter;
use App\Module\Property\Domain\PropertyPermissions;
use App\Module\Property\Domain\PropertyRepository;
use App\Module\Property\Domain\PropertyStatus;
use App\Shared\Todo\ContributesTodos;
use App\Shared\Todo\Todo;
use App\Shared\Todo\TodoKind;
use App\Shared\Todo\Urgency;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

/**
 * Was die Objekte auf die Uebersicht melden.
 *
 * Ein Objekt im Entwurf ist angefangene Arbeit — es steht in keiner
 * Abrechnung und in keinem Plan, bis es fertig ist. Ein Objekt ohne
 * Bankverbindung ist fertig und trotzdem unbrauchbar: auf seinen Schreiben
 * stuende kein Konto. Beides faellt erst auf, wenn es zu spaet ist.
 */
#[AsTaggedItem(priority: 70)]
final readonly class PropertyTodos implements ContributesTodos
{
    public function __construct(
        private PropertyRepository $properties,
        private AuthorizationCheckerInterface $mayView,
        private UrlGeneratorInterface $urls,
    ) {
    }

    public function todos(): array
    {
        if (!$this->mayView->isGranted(PropertyPermissions::VIEW)) {
            return [];
        }

        return [...$this->drafts(), ...$this->withoutAnAccount()];
    }

    /**
     * @return list<Todo>
     */
    private function drafts(): array
    {
        $count = $this->properties->countMatching(PropertyFilter::of(null, null, PropertyStatus::Draft->value));

        if (0 === $count) {
            return [];
        }

        $url = $this->urls->generate('app_property', ['status' => PropertyStatus::Draft->value]);

        return [new Todo(
            kind: TodoKind::Draft,
            urgency: Urgency::Neutral,
            labelKey: 'todo.property.drafts',
            params: ['%count%' => $count],
            count: $count,
            url: $url,
            actionKey: 'todo.action.continue',
            actionUrl: $url,
        )];
    }

    /**
     * @return list<Todo>
     */
    private function withoutAnAccount(): array
    {
        $count = $this->properties->countWithoutAnAccount();

        if (0 === $count) {
            return [];
        }

        $url = $this->urls->generate('app_property');

        return [new Todo(
            kind: TodoKind::Missing,
            urgency: Urgency::Warning,
            labelKey: 'todo.property.no_account',
            params: ['%count%' => $count],
            count: $count,
            url: $url,
            actionKey: 'todo.action.complete',
            actionUrl: $url,
        )];
    }
}
