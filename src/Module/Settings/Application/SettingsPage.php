<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Settings\Application;

use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Der Rahmen der Einstellungsseite.
 *
 * Dieselbe Gestalt wie ein Ablauf: links die Abschnitte, rechts einer davon.
 * Es ist keiner — man springt, statt weiterzugehen —, aber wer ein Formular
 * in ImmoBase kennt, soll auch diese Seite kennen.
 *
 * Als eigener Dienst, damit der Controller nicht uebersetzt: er entscheidet,
 * was passiert, nicht wie es heisst.
 */
final readonly class SettingsPage
{
    /**
     * Vom Allgemeinen zum Besonderen. „Sicherheit" steht hinten: es ist der
     * Rest, den man selten anfasst.
     *
     * @var non-empty-list<string>
     */
    public const array SECTIONS = ['app', 'organisation', 'logo', 'portal', 'sicherheit'];

    /**
     * Abschnitte, die einem anderen Modul gehören: Schlüssel und Routenname.
     *
     * Plugins stehen unter den Einstellungen, werden aber vom Plugin-Modul
     * ausgeliefert — es hält die Manifeste, die Aktivierung und die Token.
     * Hier steht deshalb nur der Routenname und keine Klasse: die
     * Einstellungen führen die Liste ihrer Abschnitte, sie bauen die fremde
     * Seite nicht.
     *
     * @var array<string, string>
     */
    public const array FOREIGN = ['plugins' => 'app_plugin_settings'];

    public function __construct(
        private UrlGeneratorInterface $urls,
        private TranslatorInterface $translator,
    ) {
    }

    /**
     * Was die Vorlage zum Zeichnen braucht.
     *
     * Was aus der Adresszeile kommt, ist Eingabe: ein unbekannter Abschnitt
     * ist kein Fehler, sondern eine Angabe, die es so nicht gibt — dann
     * steht der erste da.
     *
     * @return array{sections: list<array{key: string, label: string, url: string}>, current: string, heading: string, subheading: string, title: string, explanation: string, trail: list<array{label: string, url: string|null}>}
     */
    public function frame(string $chosen): array
    {
        $keys = [...self::SECTIONS, ...array_keys(self::FOREIGN)];
        $current = \in_array($chosen, $keys, true) ? $chosen : self::SECTIONS[0];

        return [
            'sections' => array_map(fn (string $key): array => [
                'key' => $key,
                'label' => $this->translator->trans('settings.section.'.$key),
                'url' => isset(self::FOREIGN[$key])
                    ? $this->urls->generate(self::FOREIGN[$key])
                    : $this->urls->generate('app_settings', ['abschnitt' => $key]),
            ], $keys),
            'current' => $current,
            'heading' => $this->translator->trans('settings.heading'),
            'subheading' => $this->translator->trans('settings.subheading'),
            'title' => $this->translator->trans('settings.section.'.$current),
            'explanation' => $this->translator->trans('settings.section_explanation.'.$current),
            'trail' => [
                ['label' => 'ImmoBase', 'url' => $this->urls->generate('app_dashboard')],
                ['label' => $this->translator->trans('settings.heading'), 'url' => null],
            ],
        ];
    }
}
