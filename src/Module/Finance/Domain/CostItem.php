<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Finance\Domain;

use App\Shared\Identity\Uuid;
use App\Shared\Text\Trimmed;
use DateTimeImmutable;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Eine Kostenposition: was ein Objekt kostet, und wie es sich verteilt.
 *
 * Die Position traegt die Regel — Kostenart, Objekt, Verteilerschluessel,
 * Faelligkeit. Was sie kostet, haengt als Jahreswert daran: das laufende Jahr
 * wird erfasst, das vergangene abgerechnet, und keins ueberschreibt das
 * andere. Dieselbe Form wie die Mietstaffel.
 *
 * Vorauszahlungen stehen nicht hier. Eine Tabelle mit `kind` und nullbaren
 * Fremdschluesseln waere das `CostItem` des Vorgaengersystems — 5.075 Zeilen,
 * fuenfzehn optionale Verweise und Waechter dagegen, dass sich zwei
 * widersprechen.
 */
#[ORM\Entity]
#[ORM\Table(name: 'finance_cost_item')]
#[ORM\UniqueConstraint(name: 'finance_cost_item_number', columns: ['number'])]
#[ORM\Index(name: 'finance_cost_item_property', columns: ['property_id'])]
class CostItem
{
    #[ORM\Id]
    #[ORM\Column(type: Types::GUID)]
    private string $id;

    /** Die sichtbare Nummer, ab 40001 — eigener Zahlenraum je Datenart. */
    #[ORM\Column(type: Types::INTEGER)]
    private int $number;

    #[ORM\Column(name: 'property_id', type: Types::GUID)]
    private string $propertyId;

    #[ORM\ManyToOne(targetEntity: CostKind::class)]
    #[ORM\JoinColumn(name: 'cost_kind_id', nullable: false, onDelete: 'RESTRICT')]
    private CostKind $kind;

    #[ORM\ManyToOne(targetEntity: DistributionKey::class)]
    #[ORM\JoinColumn(name: 'distribution_key_id', nullable: false, onDelete: 'RESTRICT')]
    private DistributionKey $key;

    #[ORM\Embedded(class: DueDate::class, columnPrefix: false)]
    private DueDate $due;

    #[ORM\Embedded(class: Apportionment::class, columnPrefix: false)]
    private Apportionment $apportionment;

    #[ORM\Embedded(class: Metering::class, columnPrefix: false)]
    private Metering $metering;

    #[ORM\Column(type: Types::TEXT)]
    private string $note = '';

    #[ORM\Embedded(class: ChosenMeasure::class, columnPrefix: false)]
    private ChosenMeasure $measure;

    /** Bis wann sie lief — leer heisst: sie laeuft. Warum beendet und nicht geloescht: {@see \App\Module\Finance\Application\SaveCostItem::remove()}. */
    #[ORM\Column(name: 'ends_on', type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?DateTimeImmutable $endsOn = null;

    /** @var Collection<int, CostItemYear> */
    #[ORM\OneToMany(targetEntity: CostItemYear::class, mappedBy: 'item', cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['fiscalYear' => 'DESC'])]
    private Collection $years;

    public function __construct(int $number, string $propertyId, CostKind $kind, DistributionKey $key)
    {
        $this->id = Uuid::v4();
        $this->number = $number;
        $this->propertyId = $propertyId;
        $this->kind = $kind;
        $this->key = $key;
        $this->due = DueDate::monthly();
        $this->apportionment = new Apportionment();
        $this->metering = new Metering();
        $this->measure = ChosenMeasure::none();
        $this->years = new ArrayCollection();
    }

    public function id(): string
    {
        return $this->id;
    }

    public function number(): int
    {
        return $this->number;
    }

    public function propertyId(): string
    {
        return $this->propertyId;
    }

    public function kind(): CostKind
    {
        return $this->kind;
    }

    public function key(): DistributionKey
    {
        return $this->key;
    }

    public function belongsTo(string $propertyId, CostKind $kind, DistributionKey $key): void
    {
        $this->propertyId = $propertyId;
        $this->kind = $kind;
        $this->key = $key;
    }

    public function due(): DueDate
    {
        return $this->due;
    }

    public function dueOn(DueDate $due): void
    {
        $this->due = $due;
    }

    public function isApportionable(): bool
    {
        return $this->apportionment->isApportionable($this->kind->isApportionable());
    }

    public function overridesApportionability(): bool
    {
        return $this->apportionment->overrides();
    }

    public function apportionAs(?bool $apportionable): void
    {
        $this->apportionment = $this->apportionment->apportionedAs($apportionable);
    }

    public function splitsByDay(): bool
    {
        return $this->apportionment->splitsByDay();
    }

    public function splitBy(bool $byDay): void
    {
        $this->apportionment = $this->apportionment->splittingBy($byDay);
    }

    /** Gemessen wird nur, wo der Schluessel eine Erfassung verlangt. */
    public function isMetered(): bool
    {
        return $this->key->kind()->isMetered();
    }

    public function measure(): ?UnitOfMeasure
    {
        return $this->metering->measure();
    }

    public function measureIn(?UnitOfMeasure $measure): void
    {
        $this->metering = $this->metering->in($measure, $this->isMetered());
    }

    /** Die Jahreswerte — das juengste zuerst. */
    public function years(): CostItemYears
    {
        return CostItemYears::of(array_values($this->years->toArray()));
    }

    public function add(CostItemYear $year): void
    {
        $this->years->add($year);
    }

    public function remove(CostItemYear $year): void
    {
        $this->years->removeElement($year);
    }

    public function endsOn(): ?DateTimeImmutable
    {
        return $this->endsOn;
    }

    public function isPast(): bool
    {
        return null !== $this->endsOn;
    }

    /** Beenden — oder mit `null` wieder aufnehmen. */
    public function runsUntil(?DateTimeImmutable $day): void
    {
        $this->endsOn = $day;
    }

    /** `measure()` ist hier schon die Mengeneinheit — darum der laengere Name. */
    public function decidedMeasure(): ChosenMeasure
    {
        return $this->measure;
    }

    public function paysFor(ChosenMeasure $measure): void
    {
        $this->measure = $measure;
    }

    public function note(): string
    {
        return $this->note;
    }

    public function noteThat(string $note): void
    {
        $this->note = Trimmed::orNull($note) ?? '';
    }
}
