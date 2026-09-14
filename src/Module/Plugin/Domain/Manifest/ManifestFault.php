<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Plugin\Domain\Manifest;

use RuntimeException;

/**
 * Ein Manifest sagt nicht, was es sagen muss.
 *
 * Traegt die Stelle mit, an der es hakt. Ein Plugin, das still nicht
 * erscheint, waere die schlechtere Auskunft: der Betreiber sieht ein leeres
 * Verzeichnis und weiss nicht, ob er es falsch abgelegt hat oder ob die
 * Anwendung es nicht mag.
 */
final class ManifestFault extends RuntimeException
{
    public static function at(string $where, string $why): self
    {
        return new self(\sprintf('%s: %s', $where, $why));
    }
}
