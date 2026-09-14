<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Finance\Domain;

use App\Shared\Identity\Uuid;
use App\Shared\Number\Decimal;
use App\Shared\Text\Trimmed;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Ein Verteilerschluessel: wie sich eine Summe auf die Einheiten verteilt.
 *
 * Ohne Objekt ist er ein Systemschluessel und gilt ueberall: Flaeche und
 * Miteigentumsanteile sind in jedem Haus dasselbe. Mit Objekt gehoert er
 * dorthin — „Anteile Tiefgarage" gehoert zu dem Haus, das eine hat.
 *
 * `propertyId` ist eine blosse Kennung, wie ueberall bei Verweisen ueber
 * Modulgrenzen. Der Fremdschluessel in der Datenbank steht trotzdem.
 */
#[ORM\Entity]
#[ORM\Table(name: 'finance_distribution_key')]
#[ORM\Index(name: 'finance_key_property', columns: ['property_id'])]
class DistributionKey
{
    #[ORM\Id]
    #[ORM\Column(type: Types::GUID)]
    private string $id;

    /** Leer heisst: gilt fuer alle Objekte. */
    #[ORM\Column(name: 'property_id', type: Types::GUID, nullable: true)]
    private ?string $propertyId;

    #[ORM\Column(type: Types::STRING, length: 120)]
    private string $name;

    #[ORM\Column(type: Types::STRING, length: 16, enumType: DistributionKeyKind::class)]
    private DistributionKeyKind $kind;

    #[ORM\Column(name: 'is_system', type: Types::BOOLEAN)]
    private bool $system;

    /** @var Collection<int, DistributionKeyShare> */
    #[ORM\OneToMany(
        targetEntity: DistributionKeyShare::class,
        mappedBy: 'key',
        cascade: ['persist', 'remove'],
        orphanRemoval: true,
    )]
    private Collection $shares;

    public function __construct(
        ?string $propertyId,
        string $name,
        DistributionKeyKind $kind,
        bool $system = false,
    ) {
        $this->id = Uuid::v4();
        $this->propertyId = $propertyId;
        $this->kind = $kind;
        $this->system = $system;
        $this->shares = new ArrayCollection();
        $this->rename($name);
    }

    public function id(): string
    {
        return $this->id;
    }

    public function propertyId(): ?string
    {
        return $this->propertyId;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function kind(): DistributionKeyKind
    {
        return $this->kind;
    }

    public function isSystem(): bool
    {
        return $this->system;
    }

    public function rename(string $name): void
    {
        $this->name = Trimmed::required($name, 'Die Bezeichnung');
    }

    /** @return list<DistributionKeyShare> */
    public function shares(): array
    {
        return array_values($this->shares->toArray());
    }

    /**
     * Die Summe der Anteile — exakt.
     *
     * Nicht in der Vorlage aufaddiert: dort waeren es `float`, und aus 3,5
     * wurde beim Anzeigen eine 3. Eine falsche Summe unter einem
     * Verteilerschluessel faellt niemandem auf, bis die Abrechnung nicht
     * aufgeht.
     */
    public function totalShare(): string
    {
        return Decimal::sum(array_map(
            static fn (DistributionKeyShare $share): string => $share->share(),
            $this->shares(),
        ));
    }

    public function add(DistributionKeyShare $share): void
    {
        $this->shares->add($share);
    }

    public function remove(DistributionKeyShare $share): void
    {
        $this->shares->removeElement($share);
    }
}
