<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Dunning\UserInterface\Controller;

use App\Module\Dunning\Domain\Notice;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Was die Vorlage zum Zeichnen eines Mahnschrittes braucht.
 *
 * Getrennt vom Controller, damit der bei den Aktionen bleibt: er entscheidet,
 * was passiert, nicht wie es heisst.
 */
final readonly class NoticeFlowPage
{
    public function __construct(
        private UrlGeneratorInterface $urls,
        private TranslatorInterface $translator,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function frame(Notice $notice, string $step, bool $editable = true): array
    {
        return [
            'sections' => array_map(
                fn (string $key): array => $this->section($editable ? $notice : null, $key),
                NoticeFlow::keys(),
            ),
            'current' => $step,
            'title' => $this->translator->trans('dunning.step.'.NoticeFlow::name($step)),
            'explanation' => $this->translator->trans('dunning.explanation.'.NoticeFlow::name($step)),
            'position' => $this->translator->trans('flow.step_position', [
                '%position%' => NoticeFlow::positionOf($step),
                '%count%' => NoticeFlow::count(),
            ]),
            'heading' => $this->translator->trans('dunning.heading'),
            'subheading' => $notice->reference().' · '.$this->translator->trans($notice->level()->labelKey()),
            'hasPrevious' => null !== NoticeFlow::previous($step),
            'isLast' => null === NoticeFlow::next($step),
            'action' => $this->urls->generate('app_dunning_notice_edit', ['id' => $notice->id(), 'step' => $step]),
            'cancel' => $this->urls->generate('app_dunning'),
        ];
    }

    /**
     * @return array{key: string, label: string, url: string|null}
     */
    private function section(?Notice $notice, string $key): array
    {
        return [
            'key' => $key,
            'label' => $this->translator->trans('dunning.step.'.NoticeFlow::name($key)),
            'url' => null === $notice
                ? null
                : $this->urls->generate('app_dunning_notice_edit', ['id' => $notice->id(), 'step' => $key]),
        ];
    }
}
