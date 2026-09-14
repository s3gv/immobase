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
 * Eine Einheit im Objekt — Wohnung, Laden, Stellplatz.
 *
 * Der Miteigentumsanteil haengt an ihr und nicht an ihren Eigentuemern: eine
 * Einheit hat ihren Anteil aus der Teilungserklaerung, auch solange niemand
 * eingetragen ist. Die Eigentuemer teilen ihn danach unter sich auf.
 *
 * Die Nummer laeuft innerhalb des Objekts, nicht ueber alle Objekte hinweg:
 * „Einheit 3" ist eine Auskunft, „Einheit 30417" keine.
 */
#[ORM\Entity]
#[ORM\Table(name: 'property_unit')]
#[ORM\UniqueConstraint(name: 'property_unit_number', columns: ['property_id', 'number'])]
class Unit
{
    #[ORM\Id]
    #[ORM\Column(type: Types::GUID)]
    private string $id;

    #[ORM\ManyToOne(targetEntity: Property::class, inversedBy: 'units')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Property $property;

    #[ORM\Column(type: Types::INTEGER)]
    private int $number;

    #[ORM\Column(type: Types::STRING, length: 200)]
    private string $label;

    #[ORM\Column(type: Types::STRING, length: 16, enumType: UnitUsage::class)]
    private UnitUsage $usage = UnitUsage::Residential;

    #[ORM\Embedded(class: UnitManagement::class, columnPrefix: false)]
    private UnitManagement $management;

    #[ORM\Embedded(class: Measures::class, columnPrefix: false)]
    private Measures $measures;

    /** Der Zaehler; der Nenner steht am Objekt und gilt fuer alle Einheiten. */
    #[ORM\Column(type: Types::DECIMAL, precision: 12, scale: 2)]
    private string $meaNumerator = '0';

    #[ORM\Column(type: Types::TEXT)]
    private string $note = '';

    /** @var Collection<int, UnitOwner> */
    #[ORM\OneToMany(targetEntity: UnitOwner::class, mappedBy: 'unit', cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $owners;

    /** @var Collection<int, UnitHousehold> */
    #[ORM\OneToMany(targetEntity: UnitHousehold::class, mappedBy: 'unit', cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['startsOn' => 'ASC'])]
    private Collection $households;

    public function __construct(Property $property, string $label)
    {
        $this->id = Uuid::v4();
        $this->property = $property;
        $this->number = self::nextNumberIn($property);
        $this->label = Trimmed::required($label, 'Die Bezeichnung');
        $this->management = UnitManagement::active();
        $this->measures = Measures::unknown();
        $this->owners = new ArrayCollection();
        $this->households = new ArrayCollection();

        $property->add($this);
    }

    public function id(): string
    {
        return $this->id;
    }

    public function property(): Property
    {
        return $this->property;
    }

    public function number(): int
    {
        return $this->number;
    }

    public function label(): string
    {
        return $this->label;
    }

    public function usage(): UnitUsage
    {
        return $this->usage;
    }

    public function management(): UnitManagement
    {
        return $this->management;
    }

    /** Als Ganzes gesetzt; die Uebergaenge kennt UnitManagement. */
    public function managedAs(UnitManagement $management): void
    {
        $this->management = $management;
    }

    public function status(): UnitStatus
    {
        return $this->management->status();
    }

    public function describe(string $label, UnitUsage $usage): void
    {
        $this->label = Trimmed::required($label, 'Die Bezeichnung');
        $this->usage = $usage;
    }

    public function measures(): Measures
    {
        return $this->measures;
    }

    public function measure(Measures $measures): void
    {
        $this->measures = $measures;
    }

    public function mea(): Mea
    {
        return Mea::of($this->meaNumerator, $this->property->shares()->denominator()->value);
    }

    public function holdShare(Mea $mea): void
    {
        $this->meaNumerator = $mea->numerator();
    }

    public function note(): string
    {
        return $this->note;
    }

    public function noteThat(string $note): void
    {
        $this->note = trim($note);
    }

    /** @return list<UnitOwner> */
    public function owners(): array
    {
        return array_values($this->owners->toArray());
    }

    public function addOwner(UnitOwner $owner): void
    {
        $this->owners->add($owner);
    }

    /**
     * Die Personenzahl fuer die Tage ohne Mietverhaeltnis.
     *
     * Selbst bewohnt oder leer stehend — beides ist derselbe Fall: es gibt
     * keinen Mietvertrag, an dem eine Zahl haengen koennte. Siehe
     * {@see UnitHousehold}.
     */
    public function household(): UnitHouseholds
    {
        return UnitHouseholds::of(array_values($this->households->toArray()));
    }

    public function addHousehold(UnitHousehold $step): void
    {
        $this->households->add($step);
    }

    public function removeHousehold(UnitHousehold $step): void
    {
        $this->households->removeElement($step);
    }

    public function removeOwner(UnitOwner $owner): void
    {
        $this->owners->removeElement($owner);
    }

    /** Was **je** eingetragen wurde — die Anteilsfragen stellt {@see OwnerShares}. */
    public function everHeldByOwners(): Mea
    {
        return OwnerShares::everHeld($this);
    }

    /**
     * Die naechste freie Nummer innerhalb des Objekts — nach dem Loeschen
     * nicht neu vergeben. Eine Nummer, die in der Seitenleiste steht und sich
     * aendert, ist keine. Zwei gleichzeitige Anlagen laufen in den eindeutigen
     * Index: laut statt still zweimal dieselbe Nummer.
     */
    private static function nextNumberIn(Property $property): int
    {
        return array_reduce(
            $property->units(),
            static fn (int $highest, self $unit): int => max($highest, $unit->number()),
            0,
        ) + 1;
    }
}
