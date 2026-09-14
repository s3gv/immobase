<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Tenancy\Domain;

use App\Shared\Identity\Uuid;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Wer mietet — die Zuordnung zwischen Mietverhaeltnis und Stammdatensatz.
 *
 * Kein Anteil, keine Reihenfolge, kein Hauptmieter. Wer im Vertrag steht,
 * haftet gesamtschuldnerisch; das ist keine Aufteilung, die abzubilden waere.
 *
 * `partyId` ist eine blosse Kennung und keine Verknuepfung im Sinne von
 * Doctrine: die Stammdaten liegen in einem anderen Modul, und eine Assoziation
 * dorthin waere genau die Kopplung, die die Modulgrenze verhindern soll. Der
 * Fremdschluessel in der Datenbank steht trotzdem — siehe die Migration.
 */
#[ORM\Entity]
#[ORM\Table(name: 'tenancy_tenant')]
#[ORM\UniqueConstraint(name: 'tenancy_tenant_once', columns: ['tenancy_id', 'party_id'])]
class Tenant
{
    #[ORM\Id]
    #[ORM\Column(type: Types::GUID)]
    private string $id;

    #[ORM\ManyToOne(targetEntity: Tenancy::class, inversedBy: 'tenants')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Tenancy $tenancy;

    #[ORM\Column(name: 'party_id', type: Types::GUID)]
    private string $partyId;

    public function __construct(Tenancy $tenancy, string $partyId)
    {
        $this->id = Uuid::v4();
        $this->tenancy = $tenancy;
        $this->partyId = $partyId;

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

    public function partyId(): string
    {
        return $this->partyId;
    }
}
