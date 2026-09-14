<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Shared\Search;

/**
 * Ein getippter Suchtext als Muster fuer `LIKE` — mit Prozent und
 * Unterstrich als Zeichen, nicht als Platzhalter.
 *
 * Wer in einer Liste nach „100 %" oder „Konto_2" sucht, meint genau das. Ohne
 * Maskierung faende „_" jedes Zeichen und „%" alles. PostgreSQL nimmt den
 * Backslash als Maskierung, wenn ein `LIKE` keine eigene nennt.
 */
final class LikePattern
{
    private function __construct()
    {
    }

    public static function containing(string $text): string
    {
        return '%'.addcslashes($text, '\\%_').'%';
    }
}
