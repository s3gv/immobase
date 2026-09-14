<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Finance\Domain;

use App\Shared\Identity\Uuid;
use App\Shared\Money\Money;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use InvalidArgumentException;

/**
 * Was eine Kostenposition in einem Wirtschaftsjahr gekostet hat.
 *
 * Dieselbe Form wie die Mietstaffel: das laufende Jahr wird erfasst, das
 * vergangene abgerechnet, und keins ueberschreibt das andere. Ein einzelner
 * Betrag an der Position wuerde die Abrechnung des Vorjahres still
 * veraendern, sobald jemand die neue Rechnung eintraegt.
 *
 * Das Jahr ist das, in dem das Wirtschaftsjahr *beginnt* — bei einem Beginn
 * am 1. Juli laeuft „2026" bis zum 30. Juni 2027.
 */
#[ORM\Entity]
#[ORM\Table(name: 'finance_cost_item_year')]
#[ORM\UniqueConstraint(name: 'finance_cost_item_year_once', columns: ['cost_item_id', 'fiscal_year'])]
class CostItemYear
{
    #[ORM\Id]
    #[ORM\Column(type: Types::GUID)]
    private string $id;

    #[ORM\ManyToOne(targetEntity: CostItem::class, inversedBy: 'years')]
    #[ORM\JoinColumn(name: 'cost_item_id', nullable: false, onDelete: 'CASCADE')]
    private CostItem $item;

    #[ORM\Column(name: 'fiscal_year', type: Types::SMALLINT)]
    private int $fiscalYear;

    #[ORM\Column(type: Types::BIGINT)]
    private int $amount;

    #[ORM\Column(name: 'entry_mode', type: Types::STRING, length: 16, enumType: EntryMode::class)]
    private EntryMode $mode;

    /**
     * Die Umsatzsteuer, die im Betrag steckt — null, wenn keine.
     *
     * Sie aendert nichts an dem, was die Position gekostet hat. Sie zaehlt
     * nur fuer Mietverhaeltnisse mit Umsatzsteuer: wer optiert, zieht sie als
     * Vorsteuer ab und legt dort netto um.
     */
    #[ORM\Column(name: 'input_tax', type: Types::BIGINT)]
    private int $inputTax = 0;

    /** @var Collection<int, CostItemYearUnit> */
    #[ORM\OneToMany(
        targetEntity: CostItemYearUnit::class,
        mappedBy: 'year',
        cascade: ['persist', 'remove'],
        orphanRemoval: true,
    )]
    private Collection $units;

    public function __construct(CostItem $item, int $fiscalYear, Money $amount, EntryMode $mode)
    {
        $this->id = Uuid::v4();
        $this->item = $item;
        $this->fiscalYear = self::aYear($fiscalYear);
        $this->mode = $mode;
        $this->units = new ArrayCollection();
        $this->cost($amount, $mode);

        $item->add($this);
    }

    public function id(): string
    {
        return $this->id;
    }

    public function item(): CostItem
    {
        return $this->item;
    }

    public function fiscalYear(): int
    {
        return $this->fiscalYear;
    }

    public function moveTo(int $fiscalYear): void
    {
        $this->fiscalYear = self::aYear($fiscalYear);
    }

    public function mode(): EntryMode
    {
        return $this->mode;
    }

    /**
     * Der Betrag, der in die Abrechnung geht.
     *
     * Bei „fertig verteilt" ist er die Summe der Teile und nicht die
     * Rechnungssumme: weicht die Rechnung davon ab, ist die Differenz eine
     * eigene Position — die Abrechnungsservice-Gebuehr wird anders umgelegt
     * als der Verbrauch.
     */
    public function amount(): Money
    {
        return $this->mode->isDistributed() ? $this->distributed() : Money::fromCents($this->amount);
    }

    public function inputTax(): Money
    {
        return Money::fromCents($this->inputTax);
    }

    /**
     * Wie viel Umsatzsteuer im Betrag steckt.
     *
     * Mehr als der Betrag kann es nicht sein — auch bei „fertig verteilt",
     * wo der Betrag die Summe der Einzelwerte ist. Stehen dort noch keine,
     * ist er null, und die Steuer wird erst nach ihnen eingetragen.
     *
     * @throws InvalidArgumentException bei einer negativen Steuer
     * @throws TaxExceedsAmount
     */
    public function containsTax(Money $inputTax): void
    {
        if ($inputTax->isNegative()) {
            throw new InvalidArgumentException('Die enthaltene Umsatzsteuer kann nicht negativ sein.');
        }

        if ($inputTax->cents() > $this->amount()->cents()) {
            throw new TaxExceedsAmount();
        }

        $this->inputTax = $inputTax->cents();
    }

    /**
     * Passt die Steuer noch in den Betrag?
     *
     * Bei „fertig verteilt" aendert sich der Betrag mit jedem Einzelwert.
     * Wer ihn unter die enthaltene Steuer drueckt, wird hier abgewiesen.
     *
     * @throws TaxExceedsAmount
     */
    public function taxStillFits(): void
    {
        if ($this->inputTax > $this->amount()->cents()) {
            throw new TaxExceedsAmount();
        }
    }

    /**
     * @throws InvalidArgumentException
     */
    public function cost(Money $amount, EntryMode $mode): void
    {
        if ($amount->isNegative()) {
            throw new InvalidArgumentException('Ein Betrag kann nicht negativ sein.');
        }

        $this->amount = $amount->cents();
        $this->mode = $mode;
    }

    /** @return list<CostItemYearUnit> */
    public function units(): array
    {
        return array_values($this->units->toArray());
    }

    public function add(CostItemYearUnit $unit): void
    {
        $this->units->add($unit);
    }

    public function remove(CostItemYearUnit $unit): void
    {
        $this->units->removeElement($unit);
    }

    /**
     * Eine Jahreszahl, die eine sein kann.
     *
     * Ohne diese Pruefung nimmt ein leeres Feld die Null mit, und danach
     * steht in der Abrechnung ein Jahr, das es nicht gibt. Die Grenzen sind
     * weit gefasst: sie sollen Vertipper abfangen, nicht entscheiden, wie
     * lange jemand seine Buchhaltung aufhebt.
     *
     * @throws InvalidArgumentException
     */
    private static function aYear(int $fiscalYear): int
    {
        if ($fiscalYear < 1900 || $fiscalYear > 2999) {
            throw new InvalidArgumentException('Das ist keine Jahreszahl.');
        }

        return $fiscalYear;
    }

    /** Die Summe der Teile — was der Dienstleister verteilt hat. */
    private function distributed(): Money
    {
        $sum = Money::zero();

        foreach ($this->units as $unit) {
            $sum = $sum->plus($unit->amount() ?? Money::zero());
        }

        return $sum;
    }
}
