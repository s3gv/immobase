<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Dunning\Domain;

use App\Shared\Identity\Uuid;
use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Der Basiszinssatz nach § 247 BGB, ab einem Tag.
 *
 * Er aendert sich zum 1. Januar und zum 1. Juli und **kann negativ sein** —
 * von Mitte 2016 bis Ende 2022 lag er bei −0,88 %. Verzugszinsen sind fuenf
 * Prozentpunkte darueber (§ 288 Abs. 1), neun ohne Verbraucher (Abs. 2).
 *
 * In Basispunkten wie ueberall: 1,52 % sind 152. Ein Prozentsatz als
 * Dezimalzahl waere hier genau die Zahl, die man nicht rechnen kann.
 *
 * **Eine Zeile bekommt nur, was sich aendert.** Ein Satz gilt, bis der
 * naechste ihn abloest; dreizehn gleiche Zeilen fuer die Jahre ohne Bewegung
 * waeren dreizehn Gelegenheiten, eine falsch abzutippen.
 */
#[ORM\Entity]
#[ORM\Table(name: 'dunning_base_rate')]
#[ORM\UniqueConstraint(name: 'dunning_base_rate_from', columns: ['valid_from'])]
class BaseRate
{
    #[ORM\Id]
    #[ORM\Column(type: Types::GUID)]
    private string $id;

    #[ORM\Column(name: 'valid_from', type: Types::DATE_IMMUTABLE)]
    private DateTimeImmutable $validFrom;

    #[ORM\Column(name: 'rate_bps', type: Types::INTEGER)]
    private int $rateBps;

    public function __construct(DateTimeImmutable $validFrom, int $rateBps)
    {
        $this->id = Uuid::v4();
        $this->validFrom = $validFrom;
        $this->rateBps = $rateBps;
    }

    public function id(): string
    {
        return $this->id;
    }

    public function validFrom(): DateTimeImmutable
    {
        return $this->validFrom;
    }

    public function rateBps(): int
    {
        return $this->rateBps;
    }

    public function change(int $rateBps): void
    {
        $this->rateBps = $rateBps;
    }
}
