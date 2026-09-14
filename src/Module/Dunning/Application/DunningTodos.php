<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Dunning\Application;

use App\Module\Dunning\Contract\DunningOverview;
use App\Module\Dunning\Domain\DunningPermissions;
use App\Shared\Todo\ContributesTodos;
use App\Shared\Todo\Todo;
use App\Shared\Todo\TodoKind;
use App\Shared\Todo\Urgency;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

/**
 * Was das Mahnwesen auf die Uebersicht meldet.
 *
 * Dieselbe Zahl wie am Menuepunkt, aus derselben Quelle: eine Kachel, die
 * etwas anderes nennt als das Abzeichen daneben, ist schlimmer als keine.
 *
 * Eine faellige Mahnung entsteht von selbst — niemand legt sie an, es ist nur
 * ein Tag vergangen. Deshalb eine Meldung und kein Entwurf.
 */
#[AsTaggedItem(priority: 100)]
final readonly class DunningTodos implements ContributesTodos
{
    public function __construct(
        private DunningOverview $dunning,
        private AuthorizationCheckerInterface $mayView,
        private UrlGeneratorInterface $urls,
    ) {
    }

    public function todos(): array
    {
        if (!$this->mayView->isGranted(DunningPermissions::VIEW)) {
            return [];
        }

        $pressure = $this->dunning->pressure();

        return [
            ...$this->due('todo.dunning.letters', Urgency::Warning, $pressure->letters),
            ...$this->due('todo.dunning.court', Urgency::Danger, $pressure->forTheCourt),
        ];
    }

    /**
     * Eine Meldung, wenn es etwas zu melden gibt — sonst keine.
     *
     * Als Liste und nicht als `?Todo`: so setzt der Aufrufer sie mit `...`
     * zusammen, ohne jede einzeln auf null zu pruefen.
     *
     * @return list<Todo>
     */
    private function due(string $labelKey, Urgency $urgency, int $count): array
    {
        if (0 === $count) {
            return [];
        }

        return [new Todo(
            kind: TodoKind::Notice,
            urgency: $urgency,
            labelKey: $labelKey,
            params: ['%count%' => $count],
            count: $count,
            url: $this->urls->generate('app_dunning'),
            actionKey: 'todo.action.dunning',
            actionUrl: $this->urls->generate('app_dunning'),
        )];
    }
}
