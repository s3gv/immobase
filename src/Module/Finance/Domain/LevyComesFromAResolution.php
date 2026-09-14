<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Finance\Domain;

use RuntimeException;

/**
 * Eine Sonderumlage wird nicht von Hand gebucht.
 *
 * Sie steht im Beschluss, und was davon ankam, steht an den Zahlungen der
 * Einheiten. Die Ruecklage zeigt beides zusammen. Waere sie zusaetzlich
 * buchbar, stuende dasselbe Geld zweimal auf dem Konto — und niemand saehe
 * an, welche der beiden Zeilen die falsche ist.
 *
 * Was vor ImmoBase beschlossen wurde, steckt im Anfangsbestand.
 */
final class LevyComesFromAResolution extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('Eine Sonderumlage entsteht aus dem Beschluss und wird nicht gebucht.');
    }
}
