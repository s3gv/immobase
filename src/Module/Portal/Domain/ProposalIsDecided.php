<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Portal\Domain;

use RuntimeException;

/**
 * Ueber diesen Vorschlag ist schon entschieden.
 */
final class ProposalIsDecided extends RuntimeException
{
    public static function already(): self
    {
        return new self('change.error.decided');
    }
}
