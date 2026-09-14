<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Finance\Domain;

use DomainException;

/**
 * Was in eine Abrechnung eingegangen ist, verschwindet nicht mehr.
 *
 * Aendern darf man es — das ist der Normalfall bei einer nachtraeglich
 * entdeckten falschen Rechnung, und die Abrechnung meldet dann selbst, dass
 * eine Korrektur faellig ist. Loeschen hiesse, den Beleg fuer ein
 * zugestelltes Schreiben zu entfernen.
 */
final class UsedByAStatement extends DomainException
{
    public static function under(string $reference): self
    {
        return new self(\sprintf(
            'Das ist in Abrechnung %s eingegangen. Ändern geht, löschen nicht — für eine falsche Zahl erzeugen Sie eine Korrektur.',
            $reference,
        ));
    }
}
