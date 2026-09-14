<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Billing\Domain;

use App\Shared\Identity\Uuid;
use App\Shared\Money\Money;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Eine Zeile auf einem Einzelwirtschaftsplan — eingefroren.
 *
 * Gesamtbetrag, Verteilung und der eigene Anteil, genau wie auf der
 * Abrechnung: an dieser Zeile prueft der Eigentuemer seinen Vorschuss nach.
 * Wird eine Kostenart spaeter umbenannt, steht auf dem zugestellten Schreiben
 * weiter der alte Name.
 */
#[ORM\Entity]
#[ORM\Table(name: 'billing_plan_line')]
class PlanLine
{
    #[ORM\Id]
    #[ORM\Column(type: Types::GUID)]
    private string $id;

    #[ORM\ManyToOne(targetEntity: PlanDocument::class, inversedBy: 'lines')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private PlanDocument $document;

    #[ORM\Column(type: Types::SMALLINT)]
    private int $position;

    #[ORM\Column(name: 'line_kind', type: Types::STRING, length: 16, enumType: PlanLineKind::class)]
    private PlanLineKind $kind;

    #[ORM\Column(name: 'cost_kind_label', type: Types::STRING, length: 200)]
    private string $costKind;

    #[ORM\Embedded(class: Distribution::class, columnPrefix: false)]
    private Distribution $distribution;

    #[ORM\Column(name: 'previous_amount', type: Types::BIGINT)]
    private int $previous;

    #[ORM\Column(name: 'total_amount', type: Types::BIGINT)]
    private int $total;

    #[ORM\Column(type: Types::BIGINT)]
    private int $amount;

    #[ORM\Column(type: Types::TEXT)]
    private string $reason;

    public function __construct(
        PlanDocument $document,
        int $position,
        PlanLineKind $kind,
        string $costKind,
        Distribution $distribution,
        Money $previous,
        Money $total,
        Money $amount,
        string $reason,
    ) {
        $this->id = Uuid::v4();
        $this->document = $document;
        $this->position = $position;
        $this->kind = $kind;
        $this->costKind = $costKind;
        $this->distribution = $distribution;
        $this->previous = $previous->cents();
        $this->total = $total->cents();
        $this->amount = $amount->cents();
        $this->reason = $reason;
        $document->addLine($this);
    }

    public function id(): string
    {
        return $this->id;
    }

    public function position(): int
    {
        return $this->position;
    }

    public function kind(): PlanLineKind
    {
        return $this->kind;
    }

    public function costKind(): string
    {
        return $this->costKind;
    }

    public function distribution(): Distribution
    {
        return $this->distribution;
    }

    public function previous(): Money
    {
        return Money::fromCents($this->previous);
    }

    public function total(): Money
    {
        return Money::fromCents($this->total);
    }

    public function reason(): string
    {
        return $this->reason;
    }

    public function amount(): Money
    {
        return Money::fromCents($this->amount);
    }

    /** Wieder in die Form, aus der ein Blatt gesetzt wird. */
    public function asPlanned(): PlannedLine
    {
        return new PlannedLine(
            $this->kind,
            $this->costKind,
            $this->distribution,
            $this->previous(),
            $this->total(),
            $this->amount(),
            $this->reason,
        );
    }
}
