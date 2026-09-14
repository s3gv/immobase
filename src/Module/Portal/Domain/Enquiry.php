<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Portal\Domain;

use App\Shared\Identity\Uuid;
use App\Shared\Text\Trimmed;
use DateTimeImmutable;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Eine Anfrage: ein Gespraech zu einem Betreff.
 *
 * Nicht ein Formular mit Kategorien, Prioritaeten und Eskalationsstufen — das
 * sind die Dinge, an denen Ticketsysteme scheitern: sie verlangen vom
 * Fragenden eine Einordnung, die er nicht treffen kann.
 *
 * **Der Zustand folgt der letzten Nachricht** und wird nicht gepflegt.
 * **Gelesen wird je Seite getrennt gefuehrt**, und wer schreibt, hat gelesen
 * — sonst stuende die eigene Antwort als ungelesen im eigenen Abzeichen.
 */
#[ORM\Entity]
#[ORM\Table(name: 'portal_enquiry')]
#[ORM\UniqueConstraint(name: 'portal_enquiry_number', columns: ['number'])]
#[ORM\Index(name: 'portal_enquiry_party', columns: ['party_id'])]
class Enquiry
{
    use KeepsTrackOfReading;

    #[ORM\Id]
    #[ORM\Column(type: Types::GUID)]
    private string $id;

    #[ORM\Column(type: Types::INTEGER)]
    private int $number;

    #[ORM\Column(name: 'party_id', type: Types::GUID)]
    private string $partyId;

    #[ORM\Column(type: Types::STRING, length: 200)]
    private string $subject;

    #[ORM\Column(type: Types::STRING, length: 16, enumType: EnquiryState::class)]
    private EnquiryState $state = EnquiryState::Open;

    /** Wer sich kuemmert — Arbeitsteilung und keine Sperre. */
    #[ORM\Column(name: 'assignee_user_id', type: Types::GUID, nullable: true)]
    private ?string $assigneeUserId = null;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    private DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'last_message_at', type: Types::DATETIME_IMMUTABLE)]
    private DateTimeImmutable $lastMessageAt;

    /** Fuer die Kachel „Ø bis zur ersten Antwort". */
    #[ORM\Column(name: 'first_answer_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?DateTimeImmutable $firstAnswerAt = null;

    /**
     * Der Aenderungsvorschlag, wenn einer dazugehoert.
     *
     * Hoechstens einer: ein Vorschlag ist ein Schriftstueck mit einer
     * Entscheidung, und zwei davon in einem Gespraech waeren zwei
     * Entscheidungen mit einem Knopf.
     */
    #[ORM\OneToOne(targetEntity: Proposal::class, mappedBy: 'enquiry', cascade: ['persist', 'remove'])]
    private ?Proposal $proposal = null;

    /** @var Collection<int, Message> */
    #[ORM\OneToMany(
        targetEntity: Message::class,
        mappedBy: 'enquiry',
        cascade: ['persist', 'remove'],
        orphanRemoval: true,
    )]
    #[ORM\OrderBy(['writtenAt' => 'ASC'])]
    private Collection $messages;

    public function __construct(int $number, string $partyId, string $subject, DateTimeImmutable $at)
    {
        $this->id = Uuid::v4();
        $this->number = $number;
        $this->partyId = $partyId;
        $this->subject = Trimmed::required($subject, 'Betreff');
        $this->createdAt = $at;
        $this->lastMessageAt = $at;
        $this->messages = new ArrayCollection();
    }

    public function id(): string
    {
        return $this->id;
    }

    public function number(): int
    {
        return $this->number;
    }

    public function partyId(): string
    {
        return $this->partyId;
    }

    public function subject(): string
    {
        return $this->subject;
    }

    public function state(): EnquiryState
    {
        return $this->state;
    }

    public function assigneeUserId(): ?string
    {
        return $this->assigneeUserId;
    }

    public function createdAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function lastMessageAt(): DateTimeImmutable
    {
        return $this->lastMessageAt;
    }

    public function firstAnswerAt(): ?DateTimeImmutable
    {
        return $this->firstAnswerAt;
    }

    public function proposal(): ?Proposal
    {
        return $this->proposal;
    }

    /** @internal Von {@see Proposal} beim Anlegen aufgerufen */
    public function propose(Proposal $proposal): void
    {
        $this->proposal ??= $proposal;
    }

    /** @return list<Message> */
    public function messages(): array
    {
        return array_values($this->messages->toArray());
    }

    /**
     * @internal Von {@see Message} beim Anlegen aufgerufen
     */
    public function add(Message $message): void
    {
        if ($this->messages->contains($message)) {
            return;
        }

        $this->messages->add($message);
        $this->lastMessageAt = $message->writtenAt();

        // Wer schreibt, hat gelesen. Und der Zustand folgt: wer zuletzt
        // geschrieben hat, sagt, wer dran ist.
        if ($message->isFromStaff()) {
            $this->state = EnquiryState::Answered;
            $this->firstAnswerAt ??= $message->writtenAt();
            $this->readByStaff($message->writtenAt());

            return;
        }

        $this->state = EnquiryState::Open;
        $this->readByParty($message->writtenAt());
    }

    /** Zuweisen darf jeder, auch sich selbst. Null loest die Zuweisung. */
    public function assignTo(?string $userId): void
    {
        $this->assigneeUserId = $userId;
    }

    public function close(): void
    {
        $this->state = EnquiryState::Done;
    }
}
