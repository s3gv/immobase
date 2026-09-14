<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Property\Domain;

use App\Shared\Identity\Uuid;
use App\Shared\Number\Quantity;
use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Wie viele Personen ab diesem Tag in der Einheit leben — ohne Mietvertrag.
 *
 * Die Personenzahl hing bisher ausschliesslich am Mietverhaeltnis. Eine
 * selbstbewohnte Eigentumswohnung hat keins, eine leerstehende auch nicht —
 * und jeder Verteilerschluessel nach Personen lief fuer sie ins Leere. Das
 * trifft nicht den Randfall: in einer WEG wohnt ein guter Teil der
 * Eigentuemer selbst, und Muellabfuhr, Wasser und Hausreinigung werden
 * haeufig nach Personen verteilt.
 *
 * **Sie gilt fuer die Tage, an denen kein Mietverhaeltnis laeuft.** Damit gibt
 * es zu keinem Tag zwei Zahlen: laeuft ein Mietvertrag, zaehlt seine; laeuft
 * keiner — selbst bewohnt oder leer stehend —, zaehlt diese. Der Leerstand
 * faellt so dem Eigentuemer zu, und das ist er auch, der ihn traegt.
 *
 * Eine Staffel und kein Feld, aus demselben Grund wie beim Mietverhaeltnis:
 * abgerechnet wird ein Jahr, das vorbei ist. Wer die Zahl ueberschreibt, weil
 * ein Kind geboren wurde, veraendert still die Abrechnung des Vorjahres.
 */
#[ORM\Entity]
#[ORM\Table(name: 'property_unit_household')]
#[ORM\UniqueConstraint(name: 'property_unit_household_once', columns: ['unit_id', 'starts_on'])]
class UnitHousehold
{
    #[ORM\Id]
    #[ORM\Column(type: Types::GUID)]
    private string $id;

    #[ORM\ManyToOne(targetEntity: Unit::class, inversedBy: 'households')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Unit $unit;

    #[ORM\Column(name: 'starts_on', type: Types::DATE_IMMUTABLE)]
    private DateTimeImmutable $startsOn;

    #[ORM\Column(type: Types::SMALLINT)]
    private int $people;

    public function __construct(Unit $unit, DateTimeImmutable $startsOn, int $people)
    {
        $this->id = Uuid::v4();
        $this->unit = $unit;
        $this->startsOn = $startsOn;
        $this->house($people);

        $unit->addHousehold($this);
    }

    public function id(): string
    {
        return $this->id;
    }

    public function unit(): Unit
    {
        return $this->unit;
    }

    public function startsOn(): DateTimeImmutable
    {
        return $this->startsOn;
    }

    public function people(): int
    {
        return $this->people;
    }

    public function beginsOn(DateTimeImmutable $startsOn): void
    {
        $this->startsOn = $startsOn;
    }

    /** Null Personen gibt es: eine Wohnung kann leer stehen. */
    public function house(int $people): void
    {
        $this->people = Quantity::of($people, 'Die Personenzahl');
    }
}
