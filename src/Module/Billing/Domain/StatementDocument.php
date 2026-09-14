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
 * Ein Schreiben an einen Empfaenger.
 *
 * **Alles daran ist eingefroren** — Name, Anschrift, Betraege, Zeilen. Ein
 * Verweis auf die Kostenposition genuegte nicht: sie darf sich aendern, und
 * das zugestellte Schreiben darf es nicht. Wer umzieht, bekommt seine alte
 * Abrechnung weiter mit der alten Anschrift, unter der sie ankam.
 *
 * Ein Dokument je Einheit und nicht je Eigentuemer: die Abrechnung gehoert
 * zur Einheit, Miteigentuemer haften gemeinsam. Alle stehen im Anschriftfeld,
 * der Betrag wird nicht geteilt.
 */
#[ORM\Entity]
#[ORM\Table(name: 'billing_document')]
#[ORM\Index(name: 'billing_document_unit', columns: ['unit_id'])]
class StatementDocument
{
    #[ORM\Id]
    #[ORM\Column(type: Types::GUID)]
    private string $id;

    #[ORM\ManyToOne(targetEntity: Statement::class, inversedBy: 'documents')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Statement $statement;

    #[ORM\Column(type: Types::STRING, length: 16, enumType: StatementKind::class)]
    private StatementKind $kind;

    #[ORM\Column(name: 'unit_id', type: Types::GUID)]
    private string $unitId;

    #[ORM\Column(name: 'unit_number', type: Types::INTEGER)]
    private int $unitNumber;

    #[ORM\Column(name: 'unit_label', type: Types::STRING, length: 200)]
    private string $unitLabel;

    #[ORM\Embedded(class: Period::class, columnPrefix: false)]
    private Period $period;

    #[ORM\Embedded(class: Recipient::class, columnPrefix: false)]
    private Recipient $recipient;

    #[ORM\Embedded(class: Outcome::class, columnPrefix: false)]
    private Outcome $outcome;

    #[ORM\Column(name: 'wants_pdf', type: Types::BOOLEAN)]
    private bool $wantsPdf = true;

    #[ORM\Embedded(class: Letting::class, columnPrefix: false)]
    private Letting $letting;

    /** @var Collection<int, StatementLine> */
    #[ORM\OneToMany(targetEntity: StatementLine::class, mappedBy: 'document', cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['position' => 'ASC'])]
    private Collection $lines;

    /** @var Collection<int, StatementAdvance> */
    #[ORM\OneToMany(targetEntity: StatementAdvance::class, mappedBy: 'document', cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['dueOn' => 'ASC'])]
    private Collection $paid;

    public function __construct(
        Statement $statement,
        StatementKind $kind,
        string $unitId,
        int $unitNumber,
        string $unitLabel,
        DateTimeImmutable $periodFrom,
        DateTimeImmutable $periodTo,
        string $recipientLabel,
        string $recipientAddress,
        Letting $letting,
    ) {
        $this->id = Uuid::v4();
        $this->statement = $statement;
        $this->kind = $kind;
        $this->unitId = $unitId;
        $this->unitNumber = $unitNumber;
        $this->unitLabel = $unitLabel;
        $this->period = new Period($periodFrom, $periodTo);
        $this->recipient = new Recipient($recipientLabel, $recipientAddress);
        $this->outcome = Outcome::nothing();
        $this->letting = $letting;
        $this->lines = new ArrayCollection();
        $this->paid = new ArrayCollection();
        $statement->add($this);
    }

    public function id(): string
    {
        return $this->id;
    }

    public function kind(): StatementKind
    {
        return $this->kind;
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

    public function period(): Period
    {
        return $this->period;
    }

    public function periodFrom(): DateTimeImmutable
    {
        return $this->period->from();
    }

    public function recipient(): Recipient
    {
        return $this->recipient;
    }

    /**
     * Der Freigabetag — und damit das Briefdatum.
     *
     * Nicht „heute": ein PDF, das morgen erneut entsteht, traegt denselben
     * Tag. Solange der Lauf ein Entwurf ist, gibt es keinen.
     */
    public function releasedOn(): ?DateTimeImmutable
    {
        return $this->statement->release()->day();
    }

    public function reference(): Reference
    {
        return new Reference(
            $this->kind->shortName(),
            $this->statement->propertyNumber(),
            $this->unitNumber,
            $this->statement->fiscalYear(),
            $this->statement->number(),
            $this->statement->iteration(),
        );
    }

    public function outcome(): Outcome
    {
        return $this->outcome;
    }

    public function balance(): Money
    {
        return $this->outcome->balance();
    }

    public function total(Money $costs, Money $advances, ?Money $tax = null, ?Money $advancesTax = null): void
    {
        $this->outcome = $this->outcome->of($costs, $advances, $tax, $advancesTax);
    }

    public function letting(): Letting
    {
        return $this->letting;
    }

    public function settle(Money $balance): void
    {
        $this->outcome = $this->outcome->after($balance);
    }

    public function wantsPdf(): bool
    {
        return $this->wantsPdf;
    }

    public function wantPdf(bool $wanted): void
    {
        $this->wantsPdf = $wanted;
    }

    /** @return list<StatementLine> */
    public function lines(): array
    {
        return array_values($this->lines->toArray());
    }

    /** @return list<StatementAdvance> */
    public function payments(): array
    {
        return array_values($this->paid->toArray());
    }

    public function addLine(StatementLine $line): void
    {
        $this->lines->add($line);
    }

    public function addPayment(StatementAdvance $payment): void
    {
        $this->paid->add($payment);
    }
}
