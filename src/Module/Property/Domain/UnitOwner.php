<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Property\Domain;

use App\Shared\Identity\Uuid;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Wem eine Einheit gehoert — und zu welchem Anteil.
 *
 * Kein Haupteigentuemer und keine Reihenfolge mit Bedeutung: zwei Menschen,
 * denen eine Wohnung je zur Haelfte gehoert, sind gleichberechtigt. Wer
 * „Haupteigentuemer" einfuehrt, muss danach ueberall entscheiden, was das
 * heissen soll.
 *
 * Verwiesen wird auf den Stammdatensatz nur ueber seine Kennung: das
 * Property-Modul darf das Party-Modul nicht kennen, nur seinen Contract.
 * Deshalb steht hier eine Zeichenkette und keine Beziehung.
 *
 * **Eigentum hat einen Zeitraum** ({@see Holding}). Ein Verkauf mitten im
 * Jahr war ohne ihn nicht falsch abgerechnet, sondern unsichtbar: der Kaeufer
 * bekam die Hausgeldabrechnung fuer das ganze Jahr, einschliesslich des
 * halben, das ihm nicht gehoerte.
 */
#[ORM\Entity]
#[ORM\Table(name: 'property_unit_owner')]
#[ORM\Index(name: 'property_unit_owner_party', columns: ['party_id'])]
class UnitOwner
{
    #[ORM\Id]
    #[ORM\Column(type: Types::GUID)]
    private string $id;

    #[ORM\ManyToOne(targetEntity: Unit::class, inversedBy: 'owners')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Unit $unit;

    #[ORM\Column(type: Types::GUID)]
    private string $partyId;

    #[ORM\Column(type: Types::DECIMAL, precision: 12, scale: 2)]
    private string $meaNumerator = '0';

    #[ORM\Embedded(class: Holding::class, columnPrefix: false)]
    private Holding $holding;

    public function __construct(Unit $unit, string $partyId, Mea $mea, ?Holding $holding = null)
    {
        $this->id = Uuid::v4();
        $this->unit = $unit;
        $this->partyId = $partyId;
        $this->meaNumerator = $mea->numerator();
        $this->holding = $holding ?? Holding::always();

        $unit->addOwner($this);
    }

    public function id(): string
    {
        return $this->id;
    }

    public function unit(): Unit
    {
        return $this->unit;
    }

    public function partyId(): string
    {
        return $this->partyId;
    }

    public function mea(): Mea
    {
        return Mea::of($this->meaNumerator, $this->unit->property()->shares()->denominator()->value);
    }

    public function holdShare(Mea $mea): void
    {
        $this->meaNumerator = $mea->numerator();
    }

    public function holding(): Holding
    {
        return $this->holding;
    }

    public function holdsFrom(Holding $holding): void
    {
        $this->holding = $holding;
    }
}
