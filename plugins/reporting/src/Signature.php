<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace Reporting;

/**
 * Kommt ein Webhook wirklich vom Core?
 *
 * Der Core unterschreibt jede Zustellung: HMAC-SHA256 ueber den Rumpf, so
 * wie er ankam, mit dem SHA-256-Abdruck des eigenen Tokens als Schluessel.
 * Beide Seiten kennen diesen Abdruck; das Token selbst geht nie ueber die
 * Leitung.
 *
 * **Ohne gueltige Unterschrift passiert nichts.** Jeder Prozess im Container
 * kann an den Port des Plugins schicken — und jede Meldung stoesst eine
 * vollstaendige Spiegelung an. Unterschrieben heisst: der Core hat es gesagt.
 */
final readonly class Signature
{
    private const string PREFIX = 'sha256=';

    public static function isValid(string $body, string $header, string $token): bool
    {
        if ('' === $token || !str_starts_with($header, self::PREFIX)) {
            return false;
        }

        $expected = hash_hmac('sha256', $body, hash('sha256', $token));

        return hash_equals($expected, substr($header, \strlen(self::PREFIX)));
    }
}
