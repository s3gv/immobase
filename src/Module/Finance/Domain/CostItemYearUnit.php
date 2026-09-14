<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Finance\Domain;

use App\Shared\Identity\Uuid;
use App\Shared\Money\Money;
use App\Shared\Number\Decimals;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use InvalidArgumentException;

/**
 * Was eine Einheit in einem Wirtschaftsjahr verbraucht hat.
 *
 * Der Verbrauch immer, der Betrag nur, wenn der Dienstleister schon verteilt
 * hat. „Kein Betrag" heisst dort nicht null, sondern „rechnen wir".
 *
 * Drei Nachkommastellen: Zaehlerstaende kommen in Kubikmetern und
 * Kilowattstunden, und die dritte Stelle steht auf jeder Abrechnung.
 */
#[ORM\Entity]
#[ORM\Table(name: 'finance_cost_item_year_unit')]
#[ORM\UniqueConstraint(name: 'finance_year_unit_once', columns: ['year_id', 'unit_id'])]
class CostItemYearUnit
{
    #[ORM\Id]
    #[ORM\Column(type: Types::GUID)]
    private string $id;

    #[ORM\ManyToOne(targetEntity: CostItemYear::class, inversedBy: 'units')]
    #[ORM\JoinColumn(name: 'year_id', nullable: false, onDelete: 'CASCADE')]
    private CostItemYear $year;

    #[ORM\Column(name: 'unit_id', type: Types::GUID)]
    private string $unitId;

    #[ORM\Column(type: Types::DECIMAL, precision: 14, scale: 3)]
    private string $consumption;

    #[ORM\Column(type: Types::BIGINT, nullable: true)]
    private ?int $amount = null;

    public function __construct(CostItemYear $year, string $unitId, string $consumption, ?Money $amount)
    {
        $this->id = Uuid::v4();
        $this->year = $year;
        $this->unitId = $unitId;
        $this->record($consumption, $amount);

        $year->add($this);
    }

    public function id(): string
    {
        return $this->id;
    }

    public function year(): CostItemYear
    {
        return $this->year;
    }

    public function unitId(): string
    {
        return $this->unitId;
    }

    public function consumption(): string
    {
        return $this->consumption;
    }

    public function amount(): ?Money
    {
        return null === $this->amount ? null : Money::fromCents($this->amount);
    }

    /**
     * @throws InvalidArgumentException
     */
    public function record(string $consumption, ?Money $amount): void
    {
        // Drei Nachkommastellen zaehlen hier: Zaehler zeigen Liter mit an.
        $normalised = Decimals::normalise($consumption, thirdDecimalCounts: true);

        if (1 !== preg_match('/^\d{1,11}(\.\d{1,3})?$/D', $normalised)) {
            throw new InvalidArgumentException('Ein Verbrauch ist eine Zahl ab null mit höchstens drei Nachkommastellen.');
        }

        if (null !== $amount && $amount->isNegative()) {
            throw new InvalidArgumentException('Ein Betrag kann nicht negativ sein.');
        }

        $this->consumption = $normalised;
        $this->amount = $amount?->cents();
    }
}
