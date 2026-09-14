<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Tenancy\Domain;

use App\Shared\Identity\Uuid;
use App\Shared\Money\Money;
use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Eine Stufe der Mietstaffel: ab wann gilt welche Miete.
 *
 * Statt fester Betraege am Mietverhaeltnis eine Liste. Das deckt beides ab,
 * was in Vertraegen vorkommt: die Staffelmiete, bei der die Erhoehungen schon
 * beim Einzug feststehen, und die Indexmiete, bei der jede Anpassung
 * nachgetragen wird, sobald sie feststeht.
 *
 * Betraege in ganzen Cent, wie ueberall. Die Spalten sind bigint; das
 * Wertobjekt entsteht im Zugriff.
 */
#[ORM\Entity]
#[ORM\Table(name: 'tenancy_rent')]
#[ORM\UniqueConstraint(name: 'tenancy_rent_once', columns: ['tenancy_id', 'starts_on'])]
class RentStep
{
    #[ORM\Id]
    #[ORM\Column(type: Types::GUID)]
    private string $id;

    #[ORM\ManyToOne(targetEntity: Tenancy::class, inversedBy: 'rents')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Tenancy $tenancy;

    #[ORM\Column(name: 'starts_on', type: Types::DATE_IMMUTABLE)]
    private DateTimeImmutable $startsOn;

    #[ORM\Column(name: 'base_rent', type: Types::BIGINT)]
    private int $base = 0;

    #[ORM\Column(name: 'operating_costs', type: Types::BIGINT)]
    private int $operatingCosts = 0;

    #[ORM\Column(type: Types::BIGINT)]
    private int $heating = 0;

    #[ORM\Column(type: Types::BIGINT)]
    private int $parking = 0;

    public function __construct(Tenancy $tenancy, DateTimeImmutable $startsOn, Rent $rent)
    {
        $this->id = Uuid::v4();
        $this->tenancy = $tenancy;
        $this->startsOn = $startsOn;
        $this->charge($rent);

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

    public function rent(): Rent
    {
        return Rent::of(
            Money::fromCents($this->base),
            Money::fromCents($this->operatingCosts),
            Money::fromCents($this->heating),
            Money::fromCents($this->parking),
        );
    }

    public function charge(Rent $rent): void
    {
        $this->base = $rent->base->cents();
        $this->operatingCosts = $rent->operatingCosts->cents();
        $this->heating = $rent->heating->cents();
        $this->parking = $rent->parking->cents();
    }
}
