<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Property\Domain;

use App\Shared\Identity\Uuid;
use App\Shared\Text\Trimmed;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Ein Objekt: das Gebaeude, das verwaltet wird.
 *
 * Weder readonly noch final, wie jede Entity: Doctrine braucht veraenderbare
 * Objekte und erzeugt Proxy-Klassen.
 *
 * Es entsteht mit Bezeichnung und Verwaltungsart und ist von da an ein
 * Entwurf. Jeder weitere Schritt speichert sofort — ein Objekt ist zu gross,
 * um es in einem Zug einzugeben, und wer mitten drin einen Eigentuemer anlegen
 * muss, soll nichts verlieren.
 *
 * Was hier steht, beschreibt das Gebaeude. Was mit ihm passiert — Vertraege,
 * Abrechnungen, Geld — steht in seinem Modul. Im Vorgaengersystem hing alles
 * am Objekt, und niemand fand mehr, was er suchte.
 */
#[ORM\Entity]
#[ORM\Table(name: 'property')]
#[ORM\UniqueConstraint(name: 'property_number', columns: ['number'])]
#[ORM\Index(name: 'property_sort_name', columns: ['name'])]
class Property
{
    #[ORM\Id]
    #[ORM\Column(type: Types::GUID)]
    private string $id;

    /** Die sichtbare Nummer, ab 20001 — eigener Zahlenraum je Datenart. */
    #[ORM\Column(type: Types::INTEGER)]
    private int $number;

    #[ORM\Column(type: Types::STRING, length: 200)]
    private string $name;

    #[ORM\Embedded(class: ManagementModes::class, columnPrefix: false)]
    private ManagementModes $modes;

    #[ORM\Embedded(class: Address::class, columnPrefix: false)]
    private Address $address;

    #[ORM\Embedded(class: Management::class, columnPrefix: false)]
    private Management $management;

    #[ORM\Embedded(class: Building::class, columnPrefix: false)]
    private Building $building;

    #[ORM\Embedded(class: Heating::class, columnPrefix: false)]
    private Heating $heating;

    #[ORM\Embedded(class: LandRegister::class, columnPrefix: false)]
    private LandRegister $landRegister;

    #[ORM\Embedded(class: Shares::class, columnPrefix: false)]
    private Shares $shares;

    #[ORM\Embedded(class: Accounting::class, columnPrefix: false)]
    private Accounting $accounting;

    #[ORM\Column(type: Types::TEXT)]
    private string $note = '';

    /** @var Collection<int, Unit> */
    #[ORM\OneToMany(targetEntity: Unit::class, mappedBy: 'property', cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['number' => 'ASC'])]
    private Collection $units;

    public function __construct(int $number, string $name, ManagementModes $modes)
    {
        $this->id = Uuid::v4();
        $this->number = $number;
        $this->describe($name, $modes);
        $this->address = Address::unknown();
        $this->building = Building::unknown();
        $this->heating = Heating::unknown();
        $this->landRegister = LandRegister::unknown();
        $this->shares = Shares::inThousandths();
        $this->accounting = Accounting::initial();
        $this->management = Management::draft();
        $this->units = new ArrayCollection();
    }

    public function id(): string
    {
        return $this->id;
    }

    public function number(): int
    {
        return $this->number;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function modes(): ManagementModes
    {
        return $this->modes;
    }

    public function describe(string $name, ManagementModes $modes): void
    {
        $this->name = Trimmed::required($name, 'Die Bezeichnung');
        $this->modes = $modes;
    }

    public function address(): Address
    {
        return $this->address;
    }

    public function moveTo(Address $address): void
    {
        $this->address = $address;
    }

    public function management(): Management
    {
        return $this->management;
    }

    /** Als Ganzes gesetzt wie die Anschrift; die Uebergaenge kennt Management. */
    public function managedAs(Management $management): void
    {
        $this->management = $management;
    }

    public function status(): PropertyStatus
    {
        return $this->management->status();
    }

    public function building(): Building
    {
        return $this->building;
    }

    public function describeBuilding(Building $building): void
    {
        $this->building = $building;
    }

    public function heating(): Heating
    {
        return $this->heating;
    }

    public function useHeating(Heating $heating): void
    {
        $this->heating = $heating;
    }

    public function landRegister(): LandRegister
    {
        return $this->landRegister;
    }

    public function registerAt(LandRegister $entry): void
    {
        $this->landRegister = $entry;
    }

    public function shares(): Shares
    {
        return $this->shares;
    }

    /**
     * Verteilt zaehlt beides — Einheit und Eigentuemer, siehe {@see Shares}.
     *
     * @throws MeaAlreadyDistributed
     */
    public function scaleMeaTo(MeaDenominator $denominator): void
    {
        $this->shares = $this->shares->scaledTo($denominator, $this->units->exists(
            static fn (int $at, Unit $unit): bool => !$unit->mea()->isZero() || !$unit->everHeldByOwners()->isZero(),
        ));
    }

    public function accounting(): Accounting
    {
        return $this->accounting;
    }

    /** Als Ganzes gesetzt wie die Verwaltung; die Uebergaenge kennt Accounting. */
    public function accountsAs(Accounting $accounting): void
    {
        $this->accounting = $accounting;
    }

    public function note(): string
    {
        return $this->note;
    }

    public function noteThat(string $note): void
    {
        $this->note = trim($note);
    }

    /** @return list<Unit> */
    public function units(): array
    {
        return array_values($this->units->toArray());
    }

    public function add(Unit $unit): void
    {
        $this->units->add($unit);
    }

    public function remove(Unit $unit): void
    {
        $this->units->removeElement($unit);
    }
}
