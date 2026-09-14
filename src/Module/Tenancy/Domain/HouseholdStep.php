<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Tenancy\Domain;

use App\Shared\Identity\Uuid;
use App\Shared\Number\Quantity;
use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Wie viele Personen ab diesem Tag im Haushalt leben.
 *
 * Eine Zahl am Mietverhaeltnis waere zu wenig: die Personenzahl ist der
 * Verteilerschluessel „Personen", und abgerechnet wird ein Jahr, das vorbei
 * ist. Wer die Zahl ueberschreibt, weil ein Kind geboren wurde, veraendert
 * still die Abrechnung des Vorjahres.
 *
 * Also dieselbe Form wie die Mietstaffel, die dafuer schon dasteht: eine
 * Liste statt eines Feldes, und was an einem Tag galt, ist die letzte Stufe
 * mit Datum <= diesem Tag.
 */
#[ORM\Entity]
#[ORM\Table(name: 'tenancy_household')]
#[ORM\UniqueConstraint(name: 'tenancy_household_once', columns: ['tenancy_id', 'starts_on'])]
class HouseholdStep
{
    #[ORM\Id]
    #[ORM\Column(type: Types::GUID)]
    private string $id;

    #[ORM\ManyToOne(targetEntity: Tenancy::class, inversedBy: 'households')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Tenancy $tenancy;

    #[ORM\Column(name: 'starts_on', type: Types::DATE_IMMUTABLE)]
    private DateTimeImmutable $startsOn;

    #[ORM\Column(type: Types::SMALLINT)]
    private int $people;

    public function __construct(Tenancy $tenancy, DateTimeImmutable $startsOn, int $people)
    {
        $this->id = Uuid::v4();
        $this->tenancy = $tenancy;
        $this->startsOn = $startsOn;
        $this->house($people);

        $tenancy->add($this);
    }

    public function id(): string
    {
        return $this->id;
    }

    public function tenancy(): Tenancy
    {
        return $this->tenancy;
    }

    public function startsOn(): DateTimeImmutable
    {
        return $this->startsOn;
    }

    public function moveTo(DateTimeImmutable $startsOn): void
    {
        $this->startsOn = $startsOn;
    }

    public function people(): int
    {
        return $this->people;
    }

    public function house(int $people): void
    {
        $this->people = Quantity::of($people, 'Die Zahl der Personen im Haushalt');
    }
}
