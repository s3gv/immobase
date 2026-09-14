<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Billing\Domain;

use App\Shared\Identity\Uuid;
use App\Shared\Money\Money;
use App\Shared\Text\Trimmed;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Eine Position des Bedarfs.
 *
 * Das Angebot des Dachdeckers, die Planungskosten, der Puffer. Jede mit ihrem
 * **Jahr** — eine Massnahme ueber drei Bauabschnitte ist eine Massnahme und
 * nicht drei Plaene, und das Jahr sagt, wann das Geld gebraucht wird.
 *
 * Der Hinweis traegt, was den Betrag erklaert: „Angebot vom 14.03., gueltig
 * bis Jahresende". Er ist freiwillig, aber er ist das, was einen Beschluss in
 * der Versammlung traegt.
 */
#[ORM\Entity]
#[ORM\Table(name: 'billing_budget_position')]
class BudgetPosition
{
    #[ORM\Id]
    #[ORM\Column(type: Types::GUID)]
    private string $id;

    #[ORM\ManyToOne(targetEntity: Budget::class, inversedBy: 'positions')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Budget $budget;

    #[ORM\Column(type: Types::SMALLINT)]
    private int $ordering;

    #[ORM\Column(type: Types::STRING, length: 200)]
    private string $label = '';

    #[ORM\Column(type: Types::SMALLINT)]
    private int $year;

    #[ORM\Column(type: Types::BIGINT)]
    private int $amount = 0;

    #[ORM\Column(type: Types::TEXT)]
    private string $note = '';

    public function __construct(Budget $budget, int $ordering, int $year)
    {
        $this->id = Uuid::v4();
        $this->budget = $budget;
        $this->ordering = $ordering;
        $this->year = $year;
        $budget->add($this);
    }

    public function id(): string
    {
        return $this->id;
    }

    public function ordering(): int
    {
        return $this->ordering;
    }

    public function label(): string
    {
        return $this->label;
    }

    public function year(): int
    {
        return $this->year;
    }

    public function amount(): Money
    {
        return Money::fromCents($this->amount);
    }

    public function note(): string
    {
        return $this->note;
    }

    public function describe(string $label, int $year, Money $amount, string $note): void
    {
        $this->label = trim($label);
        $this->year = $year;
        $this->amount = max(0, $amount->cents());
        $this->note = Trimmed::orNull($note) ?? '';
    }

    /** Dieselbe Position in einer berichtigten Fassung. */
    public function copyInto(Budget $budget): self
    {
        $copy = new self($budget, $this->ordering, $this->year);
        $copy->label = $this->label;
        $copy->amount = $this->amount;
        $copy->note = $this->note;

        return $copy;
    }
}
