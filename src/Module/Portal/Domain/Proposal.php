<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Portal\Domain;

use App\Shared\Change\RecordKind;
use App\Shared\Identity\Uuid;
use DateTimeImmutable;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Ein Aenderungswunsch — und er haengt an einer Anfrage.
 *
 * **Lesen ist ein Recht, aendern ist ein Vorschlag.** Ein Portal, in dem der
 * Mieter seine Anschrift selbst umschreibt, ist ein Portal, in dem der
 * Verwalter nicht mehr weiss, was in seinen Akten steht. Ein Portal, in dem
 * er sie nur melden kann, erspart ihm zwei Telefonate.
 *
 * An einer Anfrage und nicht neben ihr: damit landet jeder Vorschlag im
 * selben Postfach wie jede Frage, und der Verwalter hat eine Liste und nicht
 * zwei. Die Begruendung einer Ablehnung geht als Nachricht ins Gespraech —
 * gelesen wird sie dort, wo gefragt wurde.
 */
#[ORM\Entity]
#[ORM\Table(name: 'portal_proposal')]
// Der eindeutige Schluessel bekommt einen Namen. Ohne ihn erfindet Doctrine
// einen („UNIQ_DB8FF96A…"), und jeder Abgleich des Schemas meldete danach eine
// Umbenennung, die nichts aendert.
#[ORM\UniqueConstraint(name: 'portal_proposal_enquiry', columns: ['enquiry_id'])]
#[ORM\Index(name: 'portal_proposal_record', columns: ['record_kind', 'record_id'])]
class Proposal
{
    #[ORM\Id]
    #[ORM\Column(type: Types::GUID)]
    private string $id;

    #[ORM\OneToOne(targetEntity: Enquiry::class, inversedBy: 'proposal')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Enquiry $enquiry;

    #[ORM\Column(name: 'record_kind', type: Types::STRING, length: 16, enumType: RecordKind::class)]
    private RecordKind $recordKind;

    #[ORM\Column(name: 'record_id', type: Types::GUID)]
    private string $recordId;

    #[ORM\Column(name: 'proposed_at', type: Types::DATETIME_IMMUTABLE)]
    private DateTimeImmutable $proposedAt;

    #[ORM\Column(type: Types::STRING, length: 16, enumType: ProposalDecision::class)]
    private ProposalDecision $decision = ProposalDecision::Pending;

    #[ORM\Column(name: 'decided_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?DateTimeImmutable $decidedAt = null;

    /** Wer entschieden hat — als Kennung, der Name steht in der Nachricht. */
    #[ORM\Column(name: 'decided_by_user_id', type: Types::GUID, nullable: true)]
    private ?string $decidedByUserId = null;

    /** @var Collection<int, ProposedField> */
    #[ORM\OneToMany(
        targetEntity: ProposedField::class,
        mappedBy: 'proposal',
        cascade: ['persist', 'remove'],
        orphanRemoval: true,
    )]
    private Collection $fields;

    public function __construct(Enquiry $enquiry, RecordKind $recordKind, string $recordId, DateTimeImmutable $at)
    {
        $this->id = Uuid::v4();
        $this->enquiry = $enquiry;
        $this->recordKind = $recordKind;
        $this->recordId = $recordId;
        $this->proposedAt = $at;
        $this->fields = new ArrayCollection();

        $enquiry->propose($this);
    }

    public function id(): string
    {
        return $this->id;
    }

    public function enquiry(): Enquiry
    {
        return $this->enquiry;
    }

    public function recordKind(): RecordKind
    {
        return $this->recordKind;
    }

    public function recordId(): string
    {
        return $this->recordId;
    }

    public function proposedAt(): DateTimeImmutable
    {
        return $this->proposedAt;
    }

    public function decision(): ProposalDecision
    {
        return $this->decision;
    }

    public function decidedAt(): ?DateTimeImmutable
    {
        return $this->decidedAt;
    }

    public function isOpen(): bool
    {
        return $this->decision->isOpen();
    }

    /** @return list<ProposedField> */
    public function fields(): array
    {
        return array_values($this->fields->toArray());
    }

    /**
     * Die vorgeschlagenen Werte, wie das besitzende Modul sie erwartet.
     *
     * @return array<string, string>
     */
    public function wanted(): array
    {
        $values = [];

        foreach ($this->fields() as $field) {
            $values[$field->key()] = $field->wanted();
        }

        return $values;
    }

    /** @internal Von {@see ProposedField} beim Anlegen aufgerufen */
    public function add(ProposedField $field): void
    {
        if (!$this->fields->contains($field)) {
            $this->fields->add($field);
        }
    }

    /**
     * Entschieden — und zwar einmal.
     *
     * Ein zweites Uebernehmen schriebe den Stand von damals ueber den von
     * heute, und niemand haette darum gebeten.
     */
    public function decide(ProposalDecision $decision, string $userId, DateTimeImmutable $at): void
    {
        if (!$this->isOpen()) {
            throw ProposalIsDecided::already();
        }

        $this->decision = $decision;
        $this->decidedByUserId = $userId;
        $this->decidedAt = $at;
    }
}
