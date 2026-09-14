<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Portal\Domain;

use App\Shared\Identity\Uuid;
use DateTimeImmutable;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Eine Nachricht im Gespraech.
 *
 * **Der Absender steht als Text da und wird nicht nachgeschlagen.** Wer die
 * Verwaltung verlaesst, soll nicht rueckwirkend aus einem Gespraech
 * verschwinden, und wer die Berufsbezeichnung wechselt, soll nicht
 * nachtraeglich in alten Nachrichten etwas anderes gewesen sein — derselbe
 * Gedanke wie bei den Empfaengern eines Mahnschreibens.
 *
 * Ohne Benutzerkennung heisst: aus dem Portal. Die Partei steht an der
 * Anfrage; eine zweite Kennung hier waere eine zweite Wahrheit darueber, wer
 * fragt.
 */
#[ORM\Entity]
#[ORM\Table(name: 'portal_message')]
#[ORM\Index(name: 'portal_message_enquiry', columns: ['enquiry_id'])]
class Message
{
    #[ORM\Id]
    #[ORM\Column(type: Types::GUID)]
    private string $id;

    #[ORM\ManyToOne(targetEntity: Enquiry::class, inversedBy: 'messages')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Enquiry $enquiry;

    #[ORM\Column(name: 'author_user_id', type: Types::GUID, nullable: true)]
    private ?string $authorUserId;

    #[ORM\Column(name: 'author_label', type: Types::STRING, length: 400)]
    private string $authorLabel;

    #[ORM\Column(type: Types::TEXT)]
    private string $body;

    #[ORM\Column(name: 'written_at', type: Types::DATETIME_IMMUTABLE)]
    private DateTimeImmutable $writtenAt;

    /** @var Collection<int, Attachment> */
    #[ORM\OneToMany(
        targetEntity: Attachment::class,
        mappedBy: 'message',
        cascade: ['persist', 'remove'],
        orphanRemoval: true,
    )]
    private Collection $attachments;

    public function __construct(
        Enquiry $enquiry,
        ?string $authorUserId,
        string $authorLabel,
        string $body,
        DateTimeImmutable $writtenAt,
    ) {
        $this->id = Uuid::v4();
        $this->enquiry = $enquiry;
        $this->authorUserId = $authorUserId;
        $this->authorLabel = $authorLabel;
        $this->body = trim($body);
        $this->writtenAt = $writtenAt;
        $this->attachments = new ArrayCollection();

        $enquiry->add($this);
    }

    public function id(): string
    {
        return $this->id;
    }

    public function enquiry(): Enquiry
    {
        return $this->enquiry;
    }

    public function authorLabel(): string
    {
        return $this->authorLabel;
    }

    public function body(): string
    {
        return $this->body;
    }

    public function writtenAt(): DateTimeImmutable
    {
        return $this->writtenAt;
    }

    public function isFromStaff(): bool
    {
        return null !== $this->authorUserId;
    }

    /** @return list<Attachment> */
    public function attachments(): array
    {
        return array_values($this->attachments->toArray());
    }

    /** @internal Von {@see Attachment} beim Anlegen aufgerufen */
    public function add(Attachment $attachment): void
    {
        if (!$this->attachments->contains($attachment)) {
            $this->attachments->add($attachment);
        }
    }

    /** @internal Vom Aufraeumlauf, wenn die Frist abgelaufen ist */
    public function forget(Attachment $attachment): void
    {
        $this->attachments->removeElement($attachment);
    }
}
