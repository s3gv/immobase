<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Portal\Application;

use App\Shared\Change\RecordKind;
use App\Shared\Change\RecordThatMayChange;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * Wer sich etwas vorschlagen laesst — nach Art aufgeloest.
 *
 * Das Portal kennt keines der besitzenden Module. Es kennt die Schnittstelle
 * und diese Registratur; ein neues Ziel spaeter ist eine Umsetzung mehr und
 * keine Zeile hier.
 *
 * Eine Art ohne Umsetzung ist kein Fehler, sondern eine Angabe, die es nicht
 * gibt — sie kommt aus der Adresszeile.
 */
final readonly class ChangeableRecords
{
    /**
     * @param iterable<RecordThatMayChange> $records
     */
    public function __construct(
        #[AutowireIterator('shared.changeable')]
        private iterable $records,
    ) {
    }

    public function of(RecordKind $kind): ?RecordThatMayChange
    {
        foreach ($this->records as $record) {
            if ($record->kind() === $kind) {
                return $record;
            }
        }

        return null;
    }
}
