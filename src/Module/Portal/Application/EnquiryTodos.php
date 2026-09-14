<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Portal\Application;

use App\Module\Portal\Domain\EnquiryRepository;
use App\Module\Portal\Domain\PortalPermissions;
use App\Module\Portal\Domain\ProposalRepository;
use App\Shared\Todo\ContributesTodos;
use App\Shared\Todo\Todo;
use App\Shared\Todo\TodoKind;
use App\Shared\Todo\Urgency;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

/**
 * Was das Portal auf die Uebersicht meldet.
 *
 * Am anderen Ende wartet ein Mensch. Eine ungelesene Anfrage und ein offener
 * Aenderungsvorschlag sind darum dringlicher als jede Zahl, die nur eine
 * Verwaltung betrifft — gezaehlt wird, was noch niemand angesehen hat, nicht
 * was noch offen ist: eine gelesene Anfrage in Arbeit gehoert nicht mehr in
 * eine rote Zahl.
 */
#[AsTaggedItem(priority: 90)]
final readonly class EnquiryTodos implements ContributesTodos
{
    public function __construct(
        private EnquiryRepository $enquiries,
        private ProposalRepository $proposals,
        private AuthorizationCheckerInterface $mayView,
        private UrlGeneratorInterface $urls,
    ) {
    }

    public function todos(): array
    {
        if (!$this->mayView->isGranted(PortalPermissions::VIEW)) {
            return [];
        }

        return [
            ...$this->waiting(
                'todo.enquiry.unread',
                Urgency::Danger,
                $this->enquiries->countUnreadByStaff(),
                'todo.action.enquiry',
                $this->urls->generate('app_enquiry', ['status' => 'unread']),
            ),
            ...$this->waiting(
                'todo.enquiry.proposals',
                Urgency::Warning,
                $this->proposals->countOpen(),
                'todo.action.decide',
                $this->urls->generate('app_enquiry'),
            ),
        ];
    }

    /**
     * Eine Meldung, wenn etwas wartet — sonst keine.
     *
     * @return list<Todo>
     */
    private function waiting(string $labelKey, Urgency $urgency, int $count, string $actionKey, string $url): array
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
            url: $url,
            actionKey: $actionKey,
            actionUrl: $url,
        )];
    }
}
