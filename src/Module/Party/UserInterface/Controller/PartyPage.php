<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Party\UserInterface\Controller;

use App\Module\Party\Domain\Party;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Die Abschnitte der Stammdatenseite.
 *
 * Dieselben wie im Ablauf, nur ohne „Pruefen": das ist ein Schritt und kein
 * Abschnitt. Wer das Anlegen kennt, findet sich auf der Seite zurecht — und
 * umgekehrt.
 *
 * Die Beschriftungen kommen aus denselben Uebersetzungsschluesseln wie die
 * Schritte. Zwei Listen, die dasselbe benennen, laufen auseinander.
 */
final readonly class PartyPage
{
    public const string KIND = 'art';
    public const string NAME = 'name';
    public const string ADDRESS = 'anschrift';
    public const string CONTACT = 'erreichbarkeit';
    public const string NOTE = 'notiz';
    public const string ENQUIRIES = 'anfragen';

    /**
     * Das Recht an den Anfragen — als Zeichenkette und nicht als Konstante
     * des Portalmoduls.
     *
     * Die Stammdaten duerfen das Portal nicht kennen; **kein Modul haengt vom
     * Portal ab.** Ein Rechteschluessel ist dafuer die schmalste Fuge: er
     * steht ohnehin in jeder Vorlage als Text.
     */
    private const string MAY_SEE_ENQUIRIES = 'enquiries.view';

    public function __construct(
        private UrlGeneratorInterface $urls,
        private TranslatorInterface $translator,
        private AuthorizationCheckerInterface $mayView,
    ) {
    }

    /**
     * @return list<string>
     */
    public static function keys(): array
    {
        return [self::KIND, self::NAME, self::ADDRESS, self::CONTACT, self::NOTE, self::ENQUIRIES];
    }

    /**
     * Ein unbekannter Abschnitt aus der Adresszeile faellt auf den ersten
     * zurueck — Eingabe, kein Programmierfehler.
     */
    public static function known(string $requested): string
    {
        return \in_array($requested, self::keys(), true) ? $requested : self::KIND;
    }

    /**
     * @return array<string, mixed>
     */
    public function sections(Party $party, string $current): array
    {
        return [
            'current' => $current,
            'sections' => array_map(
                fn (string $key): array => $this->section($party, $key),
                self::keys(),
            ),
            'heading' => $party->displayName(),
            'subheading' => $this->translator->trans('party.field.reference').' '.$party->reference(),
            'title' => $this->translator->trans('party.step.'.self::step($current).'.label'),
            // Eigene Erklaerungen und nicht die des Ablaufs: dort steht, was
            // einzugeben ist („kann uebersprungen werden"), hier steht, was zu
            // sehen ist.
            'explanation' => $this->translator->trans('party.explanation.'.self::step($current)),
        ];
    }

    /**
     * Ein Abschnitt in der Reihe links.
     *
     * „Anfragen" bleibt ohne das Recht **sichtbar, aber nicht erreichbar**.
     * Die Abschnittszahl bleibt damit fuer alle dieselbe, und dass es
     * Anfragen geben kann, ist kein Geheimnis.
     *
     * @return array{key: string, label: string, url: string|null}
     */
    private function section(Party $party, string $key): array
    {
        $reachable = self::ENQUIRIES !== $key || $this->mayView->isGranted(self::MAY_SEE_ENQUIRIES);

        return [
            'key' => $key,
            'label' => $this->translator->trans('party.step.'.self::step($key).'.label'),
            'url' => $reachable
                ? $this->urls->generate('app_party_show', [
                    'reference' => $party->reference(),
                    'abschnitt' => $key,
                ])
                : null,
        ];
    }

    /**
     * Der Schluessel in der Adresszeile ist deutsch, der im Ablauf englisch.
     * Hier laufen sie zusammen.
     */
    private static function step(string $key): string
    {
        return match ($key) {
            self::NAME => 'name',
            self::ADDRESS => 'address',
            self::CONTACT => 'contact',
            self::NOTE => 'note',
            self::ENQUIRIES => 'enquiries',
            default => 'kind',
        };
    }
}
