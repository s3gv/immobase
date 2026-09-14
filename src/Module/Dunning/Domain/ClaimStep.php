<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Dunning\Domain;

use App\Shared\Identity\Uuid;
use App\Shared\Money\Money;
use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Was seit wann offen war.
 *
 * Dieselbe Gestalt wie die Mietstaffel: ein Tag und ein Betrag, und er gilt
 * bis zur naechsten Stufe. Beim Anlegen der Forderung ein Eintrag
 * (Verzugsbeginn, voller Betrag), bei jeder Teilzahlung einer, beim Erledigen
 * einer mit null.
 *
 * **Ohne sie liesse sich nach der Zahlung nicht mehr belegen, worauf die
 * Zinsen liefen.** Die Forderung selbst hat darum kein Betragsfeld — ein
 * zweites neben der Staffel waere eine zweite Wahrheit, und die beiden liefen
 * beim ersten Nachtrag auseinander.
 */
#[ORM\Entity]
#[ORM\Table(name: 'dunning_claim_step')]
#[ORM\Index(name: 'dunning_step_claim', columns: ['claim_id'])]
class ClaimStep
{
    #[ORM\Id]
    #[ORM\Column(type: Types::GUID)]
    private string $id;

    #[ORM\ManyToOne(targetEntity: Claim::class, inversedBy: 'steps')]
    #[ORM\JoinColumn(name: 'claim_id', nullable: false, onDelete: 'CASCADE')]
    private Claim $claim;

    #[ORM\Column(name: 'starts_on', type: Types::DATE_IMMUTABLE)]
    private DateTimeImmutable $startsOn;

    #[ORM\Column(type: Types::BIGINT)]
    private int $open;

    public function __construct(Claim $claim, DateTimeImmutable $startsOn, Money $open)
    {
        $this->id = Uuid::v4();
        $this->claim = $claim;
        $this->startsOn = $startsOn;
        $this->open = $open->cents();
        $claim->add($this);
    }

    public function id(): string
    {
        return $this->id;
    }

    public function claim(): Claim
    {
        return $this->claim;
    }

    public function startsOn(): DateTimeImmutable
    {
        return $this->startsOn;
    }

    public function open(): Money
    {
        return Money::fromCents($this->open);
    }

    public function change(Money $open): void
    {
        $this->open = $open->cents();
    }
}
