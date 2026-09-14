<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Auth\UserInterface\Controller;

use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Woraus "Mein Konto" besteht.
 *
 * Dieselben vier Angaben wie im Einrichtungsablauf, nur in beliebiger
 * Reihenfolge und jede fuer sich speichernd, dazu als fuenfter Abschnitt die
 * eigenen Rechte — nur zu lesen. Die Gestalt ist dieselbe: wer ein Formular
 * in ImmoBase kennt, kennt alle.
 */
final readonly class AccountSections
{
    public const string PROFILE = 'angaben';
    public const string PASSWORD = 'passwort';
    public const string EMAIL = 'adresse';
    public const string FACTOR = 'faktor';
    public const string PERMISSIONS = 'rechte';

    public function __construct(
        private UrlGeneratorInterface $urls,
        private TranslatorInterface $translator,
    ) {
    }

    /**
     * @return list<string>
     */
    public static function keys(): array
    {
        return [self::PROFILE, self::PASSWORD, self::EMAIL, self::FACTOR, self::PERMISSIONS];
    }

    /**
     * Ein unbekannter Abschnitt aus der Adresszeile faellt auf den ersten
     * zurueck — Eingabe, kein Programmierfehler.
     */
    public static function known(string $requested): string
    {
        return \in_array($requested, self::keys(), true) ? $requested : self::PROFILE;
    }

    /**
     * @return list<array{key: string, label: string, url: string|null}>
     */
    public function all(): array
    {
        return array_map(fn (string $key): array => [
            'key' => $key,
            'label' => $this->translator->trans('user.account.section.'.$key),
            'url' => $this->urls->generate('app_account', ['abschnitt' => $key]),
        ], self::keys());
    }

    public function label(string $key): string
    {
        return $this->translator->trans('user.account.section.'.$key);
    }

    /**
     * Die Ueberschrift ueber dem Abschnitt.
     *
     * Nicht dieselbe Zeichenkette wie in der Liste: die soll kurz sein und in
     * die schmale Spalte passen, die Ueberschrift darf ausschreiben, worum es
     * geht — "Sicherheit" links, "Zwei-Faktor-Authentifizierung" oben.
     */
    public function title(string $key): string
    {
        return $this->translator->trans('user.account.title.'.$key);
    }

    public function explanation(string $key): string
    {
        return $this->translator->trans('user.account.explanation.'.$key);
    }
}
