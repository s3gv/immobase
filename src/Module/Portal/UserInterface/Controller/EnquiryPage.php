<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Portal\UserInterface\Controller;

use App\Module\Portal\Domain\Enquiry;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Was die Vorlagen der Anfragen zum Zeichnen brauchen.
 *
 * Getrennt vom Controller, damit der bei den Aktionen bleibt: er entscheidet,
 * was passiert, nicht wie es heisst. Wie {@see PortalPage} auf der anderen
 * Seite derselben Sache.
 */
final readonly class EnquiryPage
{
    public function __construct(
        private UrlGeneratorInterface $urls,
        private TranslatorInterface $translator,
    ) {
    }

    /**
     * Der Rahmen eines Schritts.
     *
     * Der dritte Schritt traegt keine Adresse, solange kein Vorschlag an der
     * Anfrage haengt: sichtbar, aber nicht erreichbar — dieselbe Gestalt wie
     * ueberall sonst, wo ein Abschnitt noch nichts zu zeigen hat.
     *
     * @return array<string, mixed>
     */
    public function frame(Enquiry $enquiry, string $current, bool $hasChange = false): array
    {
        return [
            'sections' => array_map(
                fn (string $key): array => $this->section($enquiry, $key, $hasChange),
                EnquiryFlow::keys(),
            ),
            'current' => $current,
            'heading' => $enquiry->subject(),
            'subheading' => $this->translator->trans('enquiry.number', ['%number%' => $enquiry->number()]),
            'title' => $this->translator->trans('enquiry.step.'.EnquiryFlow::name($current)),
            'explanation' => $this->translator->trans('enquiry.explanation.'.EnquiryFlow::name($current)),
            // Anhaenge und Vorschlag stehen in Tabellen mit vier Spalten. In
            // einem Abschnitt von 40rem bliebe die letzte davon hinter dem
            // Rand — und was rechts abgeschnitten ist, liest niemand.
            'wide' => EnquiryFlow::CONVERSATION !== $current,
            'trail' => [
                ['label' => 'ImmoBase', 'url' => $this->urls->generate('app_dashboard')],
                ['label' => $this->translator->trans('enquiry.heading'), 'url' => $this->urls->generate('app_enquiry')],
                ['label' => $enquiry->subject(), 'url' => null],
            ],
        ];
    }

    /**
     * @return list<array{label: string, url: string|null}>
     */
    public function trail(): array
    {
        return [
            ['label' => 'ImmoBase', 'url' => $this->urls->generate('app_dashboard')],
            ['label' => $this->translator->trans('enquiry.heading'), 'url' => null],
        ];
    }

    /**
     * Die Adresse der Liste mit __PAGE__ fuer die Seitenzahl.
     *
     * Die Filter bleiben dabei erhalten: wer auf Seite zwei blaettert, will
     * dieselbe Liste weiterlesen und nicht eine andere.
     */
    public function listUrl(Request $request): string
    {
        $parameters = array_filter([
            'zustand' => $request->query->getString('zustand'),
            'bearbeiter' => $request->query->getString('bearbeiter'),
            'q' => $request->query->getString('q'),
        ], static fn (string $value): bool => '' !== $value);

        $parameters['page'] = '__PAGE__';

        return $this->urls->generate('app_enquiry').'?'.http_build_query($parameters);
    }

    /**
     * @return array{key: string, label: string, url: string|null}
     */
    private function section(Enquiry $enquiry, string $key, bool $hasChange): array
    {
        $reachable = EnquiryFlow::CHANGE !== $key || $hasChange;

        return [
            'key' => $key,
            'label' => $this->translator->trans('enquiry.step.'.EnquiryFlow::name($key)),
            'url' => $reachable
                ? $this->urls->generate('app_enquiry_step', ['id' => $enquiry->id(), 'step' => $key])
                : null,
        ];
    }
}
