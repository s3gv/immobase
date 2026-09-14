<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Tenancy\Application;

use App\Module\Tenancy\Domain\TenancyFilter;
use App\Module\Tenancy\Domain\TenancyPermissions;
use App\Module\Tenancy\Domain\TenancyRepository;
use App\Module\Tenancy\Domain\TenancyStatus;
use App\Shared\Todo\ContributesTodos;
use App\Shared\Todo\Todo;
use App\Shared\Todo\TodoKind;
use App\Shared\Todo\Urgency;
use DateTimeImmutable;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

/**
 * Was die Miete auf die Uebersicht meldet.
 *
 * Ein endendes Mietverhaeltnis zieht Arbeit nach sich — Abnahme, Kaution,
 * Nachmieter —, und die faengt nicht am letzten Tag an. Drei Monate sind die
 * uebliche Kuendigungsfrist; wer so weit vorausschaut, kommt nicht in
 * Verzug.
 */
#[AsTaggedItem(priority: 80)]
final readonly class TenancyTodos implements ContributesTodos
{
    /** So weit wird vorausgeschaut. */
    private const string HORIZON = '+3 months';

    public function __construct(
        private TenancyRepository $tenancies,
        private AuthorizationCheckerInterface $mayView,
        private UrlGeneratorInterface $urls,
    ) {
    }

    public function todos(): array
    {
        if (!$this->mayView->isGranted(TenancyPermissions::VIEW)) {
            return [];
        }

        return [...$this->drafts(), ...$this->ending()];
    }

    /**
     * @return list<Todo>
     */
    private function drafts(): array
    {
        $count = $this->tenancies->countMatching(TenancyFilter::of(TenancyStatus::Draft->value));

        if (0 === $count) {
            return [];
        }

        $url = $this->urls->generate('app_tenancy', ['status' => TenancyStatus::Draft->value]);

        return [new Todo(
            kind: TodoKind::Draft,
            urgency: Urgency::Neutral,
            labelKey: 'todo.tenancy.drafts',
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
    private function ending(): array
    {
        $until = new DateTimeImmutable('today '.self::HORIZON);
        $count = $this->tenancies->countEndingBy($until);

        if (0 === $count) {
            return [];
        }

        $url = $this->urls->generate('app_tenancy');

        return [new Todo(
            kind: TodoKind::Deadline,
            urgency: Urgency::Warning,
            labelKey: 'todo.tenancy.ending',
            params: ['%count%' => $count],
            count: $count,
            url: $url,
            actionKey: 'todo.action.look',
            actionUrl: $url,
            dueOn: $until,
        )];
    }
}
