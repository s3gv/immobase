<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Portal\Application;

use App\Module\Portal\Domain\EnquiryFilter;
use App\Module\Portal\Domain\PortalPermissions;
use App\Shared\Figure\ContributesFigures;
use App\Shared\Figure\Figure;
use App\Shared\Figure\FigureGroup;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Wie viel am Portal anliegt — und wie schnell geantwortet wird.
 *
 * Dieselben drei Zahlen wie auf der Anfragenseite, aus derselben Quelle. Die
 * Antwortzeit ist die einzige Zahl der Uebersicht, die nichts zaehlt, sondern
 * misst: sie sagt nicht, was zu tun ist, sondern wie es zuletzt lief.
 */
#[AsTaggedItem(priority: 60)]
final readonly class EnquiryFigures implements ContributesFigures
{
    public function __construct(
        private SurveyEnquiries $survey,
        private TranslatorInterface $translator,
        private AuthorizationCheckerInterface $mayView,
        private UrlGeneratorInterface $urls,
    ) {
    }

    public function figures(): array
    {
        if (!$this->mayView->isGranted(PortalPermissions::VIEW)) {
            return [];
        }

        $tally = $this->survey->tally();

        return [
            $this->figure('open', (string) $tally['open'], $tally['open'] > 0 ? 'warning' : 'success'),
            $this->figure(
                'unread',
                (string) $tally['unread'],
                $tally['unread'] > 0 ? 'danger' : 'neutral',
                ['status' => EnquiryFilter::UNREAD],
            ),
            $this->figure('answer_time', $this->hours($tally['answeredSeconds'])),
        ];
    }

    /**
     * @param array<string, string> $query
     */
    private function figure(string $key, string $value, string $tone = 'neutral', array $query = []): Figure
    {
        return new Figure(
            group: FigureGroup::Enquiries,
            labelKey: 'figure.enquiry.'.$key,
            value: $value,
            tone: $tone,
            url: $this->urls->generate('app_enquiry', $query),
        );
    }

    /**
     * Noch nie geantwortet ist keine Null.
     *
     * „0 Stunden" hiesse: sofort. Ein Gedankenstrich heisst: dazu gibt es
     * noch nichts zu sagen, und das ist die ehrlichere Auskunft.
     */
    private function hours(?int $seconds): string
    {
        return null === $seconds
            ? '—'
            : $this->translator->trans('enquiry.hours', ['%count%' => (int) round($seconds / 3600)]);
    }
}
