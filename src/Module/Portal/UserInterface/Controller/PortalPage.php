<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Portal\UserInterface\Controller;

use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Was die Vorlage zum Zeichnen eines Portalbereichs braucht.
 *
 * Getrennt vom Controller, damit der bei den Aktionen bleibt: er entscheidet,
 * was passiert, nicht wie es heisst.
 */
final readonly class PortalPage
{
    public function __construct(
        private UrlGeneratorInterface $urls,
        private TranslatorInterface $translator,
    ) {
    }

    /**
     * Der Rahmen eines Bereichs.
     *
     * **Eine eigene Ueberschrift schlaegt die des Bereichs.** „Meine
     * Anfragen" ist der richtige Titel fuer die Liste; ueber einem Gespraech
     * gehoert dessen Betreff, und wer ihn dort nicht liest, weiss nicht, was
     * er vor sich hat. Die Erklaerung faellt dann weg: sie beschreibt den
     * Bereich und nicht diesen einen Vorgang.
     *
     * @return array<string, mixed>
     */
    public function frame(string $step, string $subheading, ?string $title = null): array
    {
        return [
            'sections' => array_map($this->section(...), PortalFlow::keys()),
            'current' => $step,
            'title' => $title ?? $this->translator->trans('portal.step.'.PortalFlow::name($step)),
            'explanation' => null === $title
                ? $this->translator->trans('portal.explanation.'.PortalFlow::name($step))
                : '',
            'heading' => $this->translator->trans('portal.heading'),
            'subheading' => $subheading,
        ];
    }

    /**
     * Ein Bereich in der Reihe links.
     *
     * „Anfragen" hat eine eigene Adresse, weil dort etwas entsteht und nicht
     * nur etwas dasteht. Die Fallunterscheidung steht hier und nicht in der
     * Vorlage: eine Vorlage, die Routennamen kennt, waere die zweite Stelle,
     * an der die Reihenfolge der Bereiche steht.
     *
     * @return array{key: string, label: string, url: string|null}
     */
    private function section(string $key): array
    {
        return [
            'key' => $key,
            'label' => $this->translator->trans('portal.step.'.PortalFlow::name($key)),
            'url' => PortalFlow::ENQUIRIES === $key
                ? $this->urls->generate('app_portal_enquiries')
                : $this->urls->generate('app_portal', ['step' => $key]),
        ];
    }
}
