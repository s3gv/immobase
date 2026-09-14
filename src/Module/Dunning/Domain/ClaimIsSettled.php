<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Dunning\Domain;

use RuntimeException;

/**
 * Was bezahlt ist, wird nicht gemahnt.
 *
 * Der Zinsanspruch ueberlebt die Hauptforderung — wer drei Monate zu spaet
 * zahlt, schuldet die Zinsen weiterhin. Ein Schreiben darueber ist aber eine
 * neue Entscheidung und keine Fortsetzung: es fordert etwas anderes als das
 * vorige.
 */
final class ClaimIsSettled extends RuntimeException
{
    public static function already(): self
    {
        return new self('dunning.error.claim_settled');
    }
}
