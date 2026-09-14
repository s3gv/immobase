<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Property\Application;

use App\Module\Property\Domain\Mea;
use App\Module\Property\Domain\Measures;
use App\Module\Property\Domain\Property;
use App\Module\Property\Domain\Unit;
use App\Module\Property\Domain\UnitRepository;
use App\Module\Property\Domain\UnitUsage;

/**
 * Einheiten anlegen, aendern, entfernen.
 */
final readonly class SaveUnit
{
    public function __construct(private UnitRepository $units)
    {
    }

    public function create(Property $property, string $label, UnitUsage $usage): Unit
    {
        $unit = new Unit($property, $label);
        $unit->describe($label, $usage);
        $this->units->save($unit);

        return $unit;
    }

    public function describe(Unit $unit, string $label, UnitUsage $usage): void
    {
        $unit->describe($label, $usage);
        $this->units->save($unit);
    }

    public function detail(Unit $unit, ?string $area, ?string $rooms, ?int $parkingSpaces, string $note): void
    {
        $unit->measure(Measures::of($area, $rooms, $parkingSpaces));
        $unit->noteThat($note);
        $this->units->save($unit);
    }

    public function holdShare(Unit $unit, Mea $mea): void
    {
        $unit->holdShare($mea);
        $this->units->save($unit);
    }

    public function remove(Unit $unit): void
    {
        $this->units->remove($unit);
    }
}
