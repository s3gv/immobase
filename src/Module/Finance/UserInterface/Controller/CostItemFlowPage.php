<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Finance\UserInterface\Controller;

use App\Module\Finance\Domain\CostItem;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Was ein Schritt der Kostenposition zum Zeichnen braucht.
 *
 * Die Schrittliste links traegt Adressen und keine Formularknoepfe: jeder
 * Schritt hat schon gespeichert, also geht nichts verloren, wenn jemand
 * springt.
 */
final readonly class CostItemFlowPage
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
    public function parameters(?CostItem $item, string $step, array $errors): array
    {
        $heading = null === $item ? 'finance.item.new.heading' : 'finance.item.edit.heading';

        return [
            'item' => $item,
            'step' => $step,
            'errors' => $errors,
            'action' => $this->actionFor($item, $step),
            'sections' => $this->steps($item),
            'current' => $step,
            'heading' => null === $item
                ? $this->translator->trans($heading)
                : $this->translator->trans('finance.item.number', ['%number%' => $item->number()]),
            'subheading' => null === $item ? $this->translator->trans('finance.item.new.explanation') : null,
            'title' => $this->translator->trans('finance.item.section.'.CostItemFlow::name($step)),
            'explanation' => $this->translator->trans('finance.item.explanation.'.CostItemFlow::name($step)),
            ...$this->place($item, $step),
            'trail' => [
                ['label' => 'ImmoBase', 'url' => $this->urls->generate('app_dashboard')],
                [
                    'label' => $this->translator->trans('finance.item.heading'),
                    'url' => $this->urls->generate('app_finance_item'),
                ],
                ['label' => $this->translator->trans($heading), 'url' => null],
            ],
        ];
    }

    /**
     * Wo im Ablauf man steht und wohin es von hier geht.
     *
     * @return array<string, mixed>
     */
    private function place(?CostItem $item, string $step): array
    {
        return [
            'position' => $this->translator->trans('flow.step_position', [
                '%position%' => CostItemFlow::positionOf($step),
                '%count%' => CostItemFlow::count(),
            ]),
            'isLast' => null !== $item && null === CostItemFlow::next($step),
            'hasPrevious' => null !== $item && null !== CostItemFlow::previous($step),
            'cancel' => null === $item
                ? $this->urls->generate('app_finance_item')
                : $this->urls->generate('app_finance_item_show', ['number' => $item->number()]),
        ];
    }

    /**
     * Beim Anlegen ist nur der erste Schritt erreichbar — die uebrigen stehen
     * blass daneben, damit sichtbar ist, was folgt.
     *
     * @return list<array{key: string, label: string, url: string|null}>
     */
    private function steps(?CostItem $item): array
    {
        return array_map(fn (string $key): array => [
            'key' => $key,
            'label' => $this->translator->trans('finance.item.section.'.CostItemFlow::name($key)),
            'url' => null === $item ? null : $this->urls->generate('app_finance_item_edit', [
                'number' => $item->number(),
                'step' => $key,
            ]),
        ], CostItemFlow::keys());
    }

    private function actionFor(?CostItem $item, string $step): string
    {
        return null === $item
            ? $this->urls->generate('app_finance_item_new')
            : $this->urls->generate('app_finance_item_edit', ['number' => $item->number(), 'step' => $step]);
    }
}
