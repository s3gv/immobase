<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Property\Application;

use App\Module\Property\Domain\Address;
use App\Module\Property\Domain\BankAccount;
use App\Module\Property\Domain\Building;
use App\Module\Property\Domain\FiscalYear;
use App\Module\Property\Domain\Heating;
use App\Module\Property\Domain\LandRegister;
use App\Module\Property\Domain\ManagementModes;
use App\Module\Property\Domain\MeaDenominator;
use App\Module\Property\Domain\Property;
use App\Module\Property\Domain\PropertyRepository;

/**
 * Ein Objekt anlegen und Schritt fuer Schritt fuellen.
 *
 * Je Schritt eine Methode statt eines grossen „speichere alles": jeder
 * Schritt schreibt genau das, was er gefragt hat. Ein Aufruf, der versehentlich
 * leere Felder mitbringt, kann so nicht ueberschreiben, was ein anderer
 * Schritt gesetzt hat.
 */
final readonly class SaveProperty
{
    public function __construct(private PropertyRepository $properties)
    {
    }

    /** Der erste Schritt legt an — ab hier ist es ein Entwurf mit Nummer. */
    public function create(string $name, ManagementModes $modes): Property
    {
        $property = new Property($this->properties->nextNumber(), $name, $modes);
        $this->properties->save($property);

        return $property;
    }

    public function describe(Property $property, string $name, ManagementModes $modes): void
    {
        $property->describe($name, $modes);
        $this->properties->save($property);
    }

    public function moveTo(Property $property, Address $address): void
    {
        $property->moveTo($address);
        $this->properties->save($property);
    }

    public function describeBuilding(Property $property, Building $building): void
    {
        $property->describeBuilding($building);
        $this->properties->save($property);
    }

    public function useHeating(Property $property, Heating $heating): void
    {
        $property->useHeating($heating);
        $this->properties->save($property);
    }

    public function accountsFrom(Property $property, FiscalYear $fiscalYear): void
    {
        $property->accountsAs($property->accounting()->beginningOn($fiscalYear));
        $this->properties->save($property);
    }

    public function collectsVia(Property $property, BankAccount $account): void
    {
        $property->accountsAs($property->accounting()->collectedVia($account));
        $this->properties->save($property);
    }

    public function registerAt(Property $property, LandRegister $entry): void
    {
        $property->registerAt($entry);
        $this->properties->save($property);
    }

    public function scaleMeaTo(Property $property, MeaDenominator $denominator): void
    {
        $property->scaleMeaTo($denominator);
        $this->properties->save($property);
    }

    public function noteThat(Property $property, string $note): void
    {
        $property->noteThat($note);
        $this->properties->save($property);
    }

    /**
     * Der letzte Schritt macht aus dem Entwurf ein Objekt.
     *
     * Beim Bearbeiten eines fertigen Objekts aendert das nichts — es ist
     * schon aktiv.
     */
    public function complete(Property $property): void
    {
        $property->managedAs($property->management()->activated());
        $this->properties->save($property);
    }
}
