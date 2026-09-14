<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Property\Application;

use App\Module\Property\Contract\Event\PropertyClosed;
use App\Module\Property\Contract\Event\UnitClosed;
use App\Module\Property\Domain\Property;
use App\Module\Property\Domain\PropertyNeedsAClosingDate;
use App\Module\Property\Domain\PropertyRepository;
use App\Module\Property\Domain\Unit;
use App\Module\Property\Domain\UnitRepository;
use DateTimeImmutable;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * Ein Objekt oder eine Einheit abwickeln — und alles, was daran haengt.
 *
 * Ein Objekt zu beenden, ohne seine Mietverhaeltnisse zu beenden, hinterliesse
 * laufende Vertraege an einer Einheit, die es nicht mehr gibt. Beenden greift
 * deshalb durch: die Einheiten hier, alles Weitere ueber das Ereignis.
 *
 * Alles in einer Anfrage und damit in einer Transaktion: entweder ist alles
 * beendet oder nichts. Ein beendetes Objekt mit laufenden Mietverhaeltnissen
 * waere schlimmer als der Zustand vorher.
 */
final readonly class CloseProperty
{
    public function __construct(
        private PropertyRepository $properties,
        private UnitRepository $units,
        private EventDispatcherInterface $events,
    ) {
    }

    /**
     * @throws PropertyNeedsAClosingDate
     */
    public function on(Property $property, ?DateTimeImmutable $day, string $note = ''): void
    {
        $property->managedAs($property->management()->closedOn($day, $note));
        $closedOn = $property->management()->closure()->day() ?? throw new PropertyNeedsAClosingDate();
        $ids = [];

        foreach ($property->units() as $unit) {
            $unit->managedAs($unit->management()->closedNoLaterThan($closedOn, $note));
            $ids[] = $unit->id();
        }

        $this->properties->atomically(function () use ($property, $ids, $closedOn): void {
            $this->properties->save($property);
            $this->events->dispatch(new PropertyClosed($property->id(), $ids, $closedOn));
        });
    }

    /**
     * Eine einzelne Einheit — bei Sondereigentumsverwaltung der Normalfall.
     *
     * @throws PropertyNeedsAClosingDate
     */
    public function unitOn(Unit $unit, ?DateTimeImmutable $day, string $note = ''): void
    {
        $unit->managedAs($unit->management()->closedOn($day, $note));
        $closedOn = $unit->management()->closure()->day() ?? throw new PropertyNeedsAClosingDate();

        $this->properties->atomically(function () use ($unit, $closedOn): void {
            $this->units->save($unit);
            $this->events->dispatch(new UnitClosed($unit->id(), $closedOn));
        });
    }

    /**
     * Wieder aufnehmen — nur das Objekt.
     *
     * Die Einheiten und ihre Mietverhaeltnisse kommen nicht zurueck: ein
     * beendetes Mietverhaeltnis wieder aufleben zu lassen waere geraten, denn
     * es kann in der Zwischenzeit richtig beendet worden sein. Die
     * Oberflaeche sagt das.
     */
    public function reopen(Property $property): void
    {
        $property->managedAs($property->management()->reopened());
        $this->properties->save($property);
    }

    /**
     * Eine einzelne Einheit zurueckholen.
     *
     * Ihre Mietverhaeltnisse kommen nicht mit — aus demselben Grund wie beim
     * Objekt. Ein neues laesst sich anlegen, sobald sie wieder da ist.
     */
    public function reopenUnit(Unit $unit): void
    {
        $unit->managedAs($unit->management()->reopened());
        $this->units->save($unit);
    }
}
