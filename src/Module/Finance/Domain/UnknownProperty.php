<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Finance\Domain;

use RuntimeException;

/**
 * Ein Objekt, das es nicht gibt.
 *
 * Faellt auf, wenn jemand ein Formular nachbaut oder wenn das Objekt in der
 * Zwischenzeit verschwunden ist. Der Fremdschluessel in der Datenbank ist die
 * letzte Grenze, diese Absage die verstaendliche.
 */
final class UnknownProperty extends RuntimeException
{
    public static function of(string $propertyId): self
    {
        return new self(\sprintf('Das Objekt %s gibt es nicht.', $propertyId));
    }
}
