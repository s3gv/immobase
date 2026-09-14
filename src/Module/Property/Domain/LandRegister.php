<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Property\Domain;

use App\Shared\Text\Trimmed;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Der Eintrag im Grundbuch.
 *
 * Vier Angaben, mit denen nichts gerechnet wird — sie werden nachgeschlagen:
 * beim Eigentuemerwechsel, bei der Teilungserklaerung, gegenueber Notar und
 * Amtsgericht. Deshalb stehen sie zusammen und in einem eigenen Schritt, der
 * sich ueberspringen laesst.
 */
#[ORM\Embeddable]
final class LandRegister
{
    #[ORM\Column(name: 'registry_court', type: Types::STRING, length: 120, nullable: true)]
    private ?string $court = null;

    #[ORM\Column(name: 'registry_sheet', type: Types::STRING, length: 32, nullable: true)]
    private ?string $sheet = null;

    #[ORM\Column(name: 'registry_district', type: Types::STRING, length: 120, nullable: true)]
    private ?string $district = null;

    #[ORM\Column(name: 'registry_parcel', type: Types::STRING, length: 64, nullable: true)]
    private ?string $parcel = null;

    private function __construct()
    {
    }

    public static function unknown(): self
    {
        return new self();
    }

    public static function of(?string $court, ?string $sheet, ?string $district, ?string $parcel): self
    {
        $entry = new self();
        $entry->court = Trimmed::orNull($court);
        $entry->sheet = Trimmed::orNull($sheet);
        $entry->district = Trimmed::orNull($district);
        $entry->parcel = Trimmed::orNull($parcel);

        return $entry;
    }

    public function court(): ?string
    {
        return $this->court;
    }

    public function sheet(): ?string
    {
        return $this->sheet;
    }

    public function district(): ?string
    {
        return $this->district;
    }

    public function parcel(): ?string
    {
        return $this->parcel;
    }

    public function isKnown(): bool
    {
        return null !== $this->court || null !== $this->sheet
            || null !== $this->district || null !== $this->parcel;
    }
}
