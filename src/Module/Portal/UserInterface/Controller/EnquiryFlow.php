<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Portal\UserInterface\Controller;

/**
 * Die drei Schritte einer Anfrage im Verwalterbereich.
 *
 * Erst das Gespraech, dann die Dateien, dann — wenn es einen gibt — der
 * Aenderungsvorschlag. Die Reihenfolge ist die der Aufmerksamkeit: was
 * jemand geschrieben hat, kommt vor dem, was er mitgeschickt hat.
 *
 * **Der dritte steht immer da und ist meistens nicht erreichbar.** Eine
 * Schrittzahl, die sich je nach Anfrage aendert, verwirrt mehr, als ein
 * blasser dritter Schritt es tut — und dass es Aenderungsvorschlaege gibt,
 * ist kein Geheimnis.
 */
final class EnquiryFlow
{
    public const string CONVERSATION = 'gespraech';
    public const string FILES = 'anhaenge';
    public const string CHANGE = 'aenderung';

    /** Der Schluessel in der Adresszeile ist deutsch, der in den Uebersetzungen englisch. */
    private const array NAMES = [
        self::CONVERSATION => 'conversation',
        self::FILES => 'files',
        self::CHANGE => 'change',
    ];

    private function __construct()
    {
    }

    /** @return non-empty-list<string> */
    public static function keys(): array
    {
        return [self::CONVERSATION, self::FILES, self::CHANGE];
    }

    /** Ein unbekannter Schritt faellt auf den ersten zurueck — Eingabe, kein Fehler. */
    public static function known(string $requested): string
    {
        return \in_array($requested, self::keys(), true) ? $requested : self::CONVERSATION;
    }

    public static function name(string $key): string
    {
        return self::NAMES[$key] ?? 'conversation';
    }
}
