<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Tests\Module\Portal\Fixture;

use App\Shared\Change\ChangeableField;
use App\Shared\Change\FieldKind;
use App\Shared\Change\RecordKind;
use App\Shared\Change\RecordThatMayChange;

/**
 * Ein Datensatz, der beim Uebernehmen verschwindet.
 *
 * Der Fall, der sich sonst nicht herstellen laesst: zwischen „spricht etwas
 * dagegen?" und dem Schreiben loescht ihn jemand anders. `apply()` kehrt dann
 * still zurueck — so steht es in der Schnittstelle —, und ohne die Nachschau
 * danach stuende der Vorschlag als uebernommen da, ohne dass sich etwas
 * geaendert haette.
 */
final class VanishingRecord implements RecordThatMayChange
{
    private bool $gone = false;

    public function kind(): RecordKind
    {
        return RecordKind::Unit;
    }

    public function fieldsOf(string $id): array
    {
        return $this->gone ? [] : [new ChangeableField('label', 'property.unit.field.label', FieldKind::Text, 'Vorher')];
    }

    public function objectionsTo(string $id, array $values): array
    {
        return [];
    }

    /** Still zurueckgekehrt: den Datensatz gibt es in diesem Augenblick nicht mehr. */
    public function apply(string $id, array $values): void
    {
        $this->gone = true;
    }
}
