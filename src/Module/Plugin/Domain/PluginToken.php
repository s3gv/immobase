<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Plugin\Domain;

use SensitiveParameter;

/**
 * Der Ausweis eines Plugins gegenueber `/api/v1/`.
 *
 * **Schlichter SHA-256 und kein HMAC.** Das Anmeldemodul legt seine Codes mit
 * einem Serverschluessel ab, weil sie kurz sind: sechs Ziffern oder fuenfzig
 * Bit lassen sich aus einem Datenbankabzug in Minuten durchprobieren. Dieses
 * Token hat 256 Bit aus dem Zufallsgenerator des Betriebssystems — es gibt
 * nichts durchzuprobieren, und ein Schluessel loeste ein Problem, das hier
 * nicht besteht.
 *
 * **Gezeigt wird es genau einmal.** Wer es verliert, bekommt kein zweites
 * Mal dasselbe, sondern ein neues; das alte gilt dann nicht mehr.
 */
final readonly class PluginToken
{
    /** Das Praefix macht ein gefundenes Token erkennbar — etwa in einem Protokoll. */
    private const string PREFIX = 'ib_';

    public static function fresh(): string
    {
        return self::PREFIX.bin2hex(random_bytes(32));
    }

    public static function seal(#[SensitiveParameter] string $token): string
    {
        return hash('sha256', $token);
    }

    public static function matches(#[SensitiveParameter] string $token, string $stored): bool
    {
        return hash_equals($stored, self::seal($token));
    }

    /** Sieht es ueberhaupt wie eines aus? Spart die Abfrage bei jedem Unsinn. */
    public static function looksLikeOne(string $candidate): bool
    {
        return 1 === preg_match('/^'.self::PREFIX.'[0-9a-f]{64}$/D', $candidate);
    }
}
