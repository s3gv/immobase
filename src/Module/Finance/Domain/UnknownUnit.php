<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Finance\Domain;

use RuntimeException;

/**
 * Eine Einheit, die es nicht gibt.
 *
 * Der Fremdschluessel in der Datenbank ist die letzte Grenze, diese Absage
 * die verstaendliche.
 */
final class UnknownUnit extends RuntimeException
{
    public static function of(string $unitId): self
    {
        return new self(\sprintf('Die Einheit %s gibt es nicht.', $unitId));
    }
}
