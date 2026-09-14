<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Finance\Domain;

use DomainException;

/**
 * Was in einen Wirtschaftsplan eingegangen ist, verschwindet nicht mehr.
 *
 * Umbenennen darf man es — der Plan traegt die Beschriftung eingefroren, und
 * das zugestellte Schreiben aendert sich davon nicht. Loeschen hiesse, dem
 * Plan die Grundlage zu entziehen, auf der er seine Vorschuesse verteilt hat.
 */
final class UsedByAPlan extends DomainException
{
    public static function under(string $reference): self
    {
        return new self(\sprintf(
            'Das ist in Wirtschaftsplan %s eingegangen und lässt sich nicht mehr löschen.',
            $reference,
        ));
    }
}
