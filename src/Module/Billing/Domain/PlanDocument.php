<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Billing\Domain;

use App\Shared\Identity\Uuid;
use App\Shared\Money\Money;
use DateTimeImmutable;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Der Einzelwirtschaftsplan einer Einheit — eingefroren.
 *
 * Ein Dokument je Einheit und nicht je Eigentuemer: der Vorschuss haengt an
 * der Einheit, Miteigentuemer haften gemeinsam. Alle stehen im Anschriftfeld,
 * der Betrag wird nicht geteilt.
 *
 * Auch nicht je Eigentuemerabschnitt — anders als bei der Abrechnung. Ein Plan
 * gilt fuer das ganze Jahr, und wer unterjaehrig kauft, uebernimmt den
 * laufenden Vorschuss. Geplant wird darum fuer den Eigentuemer, der bei der
 * Freigabe eingetragen ist.
 */
#[ORM\Entity]
#[ORM\Table(name: 'billing_plan_document')]
#[ORM\Index(name: 'billing_plan_document_unit', columns: ['unit_id'])]
class PlanDocument
{
    #[ORM\Id]
    #[ORM\Column(type: Types::GUID)]
    private string $id;

    #[ORM\ManyToOne(targetEntity: Plan::class, inversedBy: 'documents')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Plan $plan;

    #[ORM\Column(name: 'unit_id', type: Types::GUID)]
    private string $unitId;

    #[ORM\Column(name: 'unit_number', type: Types::INTEGER)]
    private int $unitNumber;

    #[ORM\Column(name: 'unit_label', type: Types::STRING, length: 200)]
    private string $unitLabel;

    #[ORM\Embedded(class: Recipient::class, columnPrefix: false)]
    private Recipient $recipient;

    /** @var Collection<int, PlanLine> */
    #[ORM\OneToMany(targetEntity: PlanLine::class, mappedBy: 'document', cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['position' => 'ASC'])]
    private Collection $lines;

    public function __construct(
        Plan $plan,
        string $unitId,
        int $unitNumber,
        string $unitLabel,
        string $recipientLabel,
        string $recipientAddress,
    ) {
        $this->id = Uuid::v4();
        $this->plan = $plan;
        $this->unitId = $unitId;
        $this->unitNumber = $unitNumber;
        $this->unitLabel = $unitLabel;
        $this->recipient = new Recipient($recipientLabel, $recipientAddress);
        $this->lines = new ArrayCollection();
        $plan->hold($this);
    }

    public function id(): string
    {
        return $this->id;
    }

    public function plan(): Plan
    {
        return $this->plan;
    }

    public function unitId(): string
    {
        return $this->unitId;
    }

    public function unitNumber(): int
    {
        return $this->unitNumber;
    }

    public function unitLabel(): string
    {
        return $this->unitLabel;
    }

    public function recipient(): Recipient
    {
        return $this->recipient;
    }

    /** Der Freigabetag — und damit das Briefdatum. */
    public function releasedOn(): ?DateTimeImmutable
    {
        return $this->plan->stage()->day();
    }

    public function reference(): Reference
    {
        return PlanReference::of($this->plan, $this->unitNumber);
    }

    /** @return list<PlanLine> */
    public function lines(): array
    {
        return array_values($this->lines->toArray());
    }

    public function addLine(PlanLine $line): void
    {
        $this->lines->add($line);
    }

    public function yearly(): Money
    {
        return $this->asPlanned()->yearly();
    }

    public function advance(): Money
    {
        return $this->asPlanned()->advance();
    }

    /**
     * Wieder in die Form, aus der ein Blatt gesetzt wird.
     *
     * Die Vorschau rechnet mit einem Vorschlag, das Schreiben entsteht aus
     * einem eingefrorenen Dokument. Beide sehen gleich aus — hier laufen sie
     * zusammen, damit es nicht zwei Wege zum Blatt gibt.
     */
    public function asPlanned(): PlannedDocument
    {
        return new PlannedDocument(
            $this->unitId,
            $this->unitNumber,
            $this->unitLabel,
            $this->recipient->label(),
            $this->recipient->address(),
            array_map(static fn (PlanLine $line): PlannedLine => $line->asPlanned(), $this->lines()),
            $this->plan->terms()->interval(),
        );
    }
}
