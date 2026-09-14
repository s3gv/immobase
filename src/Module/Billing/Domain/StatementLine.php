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
 * Eine Kostenart auf einem Schreiben.
 *
 * Gesamtbetrag, Verteilung und der eigene Anteil — mehr braucht die Zeile
 * nicht, und weniger reicht nicht: an dieser Zeile prueft der Empfaenger
 * seine Abrechnung nach.
 *
 * Alles eingefroren. Wird eine Kostenart spaeter umbenannt, steht auf dem
 * zugestellten Schreiben weiter der alte Name.
 */
#[ORM\Entity]
#[ORM\Table(name: 'billing_line')]
class StatementLine
{
    #[ORM\Id]
    #[ORM\Column(type: Types::GUID)]
    private string $id;

    #[ORM\ManyToOne(targetEntity: StatementDocument::class, inversedBy: 'lines')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private StatementDocument $document;

    #[ORM\Column(type: Types::SMALLINT)]
    private int $position;

    #[ORM\Column(name: 'cost_kind_label', type: Types::STRING, length: 200)]
    private string $costKind;

    #[ORM\Embedded(class: Distribution::class, columnPrefix: false)]
    private Distribution $distribution;

    #[ORM\Column(name: 'total_amount', type: Types::BIGINT)]
    private int $total;

    #[ORM\Column(type: Types::BIGINT)]
    private int $amount;

    /** Die Umsatzsteuer im Gesamtbetrag, wie sie am Kostenjahr stand. */
    #[ORM\Column(name: 'total_input_tax', type: Types::BIGINT)]
    private int $totalInputTax = 0;

    /** Der Anteil daran. */
    #[ORM\Column(name: 'input_tax', type: Types::BIGINT)]
    private int $inputTax = 0;

    public function __construct(
        StatementDocument $document,
        int $position,
        string $costKind,
        Distribution $distribution,
        Money $total,
        Money $amount,
    ) {
        $this->id = Uuid::v4();
        $this->document = $document;
        $this->position = $position;
        $this->costKind = $costKind;
        $this->distribution = $distribution;
        $this->total = $total->cents();
        $this->amount = $amount->cents();
        $document->addLine($this);
    }

    /**
     * Die enthaltene Umsatzsteuer festhalten.
     *
     * Eigens und nicht im Konstruktor: sie gehoert nur auf Schreiben an
     * Mietverhaeltnisse mit Umsatzsteuer, und alle anderen Zeilen entstehen
     * weiter so, wie sie immer entstanden.
     */
    public function containing(Money $totalInputTax, Money $inputTax): void
    {
        $this->totalInputTax = $totalInputTax->cents();
        $this->inputTax = $inputTax->cents();
    }

    public function totalInputTax(): Money
    {
        return Money::fromCents($this->totalInputTax);
    }

    public function inputTax(): Money
    {
        return Money::fromCents($this->inputTax);
    }

    /** Der Anteil ohne die Steuer darin — nie weniger als null. */
    public function net(): Money
    {
        return Money::fromCents(max(0, $this->amount - $this->inputTax));
    }

    public function totalNet(): Money
    {
        return Money::fromCents(max(0, $this->total - $this->totalInputTax));
    }

    public function id(): string
    {
        return $this->id;
    }

    public function position(): int
    {
        return $this->position;
    }

    public function costKind(): string
    {
        return $this->costKind;
    }

    public function distribution(): Distribution
    {
        return $this->distribution;
    }

    public function total(): Money
    {
        return Money::fromCents($this->total);
    }

    public function amount(): Money
    {
        return Money::fromCents($this->amount);
    }
}
