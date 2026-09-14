<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Portal\Domain;

use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Eine Datei an einer Nachricht — verschluesselt und befristet.
 *
 * Der Name ist **eine Beschriftung, nie ein Pfad**: es gibt keinen Pfad, und
 * ausgeliefert wird ausschliesslich ueber einen Controller, der vorher
 * prueft, ob die Datei zu einer Anfrage des Angemeldeten gehoert.
 *
 * Die gemeldete Art steht mit dabei, wird aber **nie zum Ausliefern benutzt**
 * — sie kommt vom Browser des Absenders und ist damit eine Behauptung. Was
 * herausgeht, geht als Anhang und als `application/octet-stream`.
 */
#[ORM\Entity]
#[ORM\Table(name: 'portal_attachment')]
#[ORM\Index(name: 'portal_attachment_message', columns: ['message_id'])]
#[ORM\Index(name: 'portal_attachment_expiry', columns: ['delete_after'])]
class Attachment
{
    /** Fuenf Megabyte. Es geht um Belege und Abrechnungen, nicht um Videos. */
    public const int MAX_BYTES = 5 * 1024 * 1024;

    /** Fuenf Dateien je Nachricht. */
    public const int MAX_PER_MESSAGE = 5;

    #[ORM\Id]
    #[ORM\Column(type: Types::GUID)]
    private string $id;

    #[ORM\ManyToOne(targetEntity: Message::class, inversedBy: 'attachments')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Message $message;

    #[ORM\Column(type: Types::STRING, length: 255)]
    private string $name;

    #[ORM\Column(name: 'media_type', type: Types::STRING, length: 120)]
    private string $mediaType;

    #[ORM\Column(name: 'size_bytes', type: Types::INTEGER)]
    private int $sizeBytes;

    #[ORM\Embedded(class: SealedFile::class, columnPrefix: false)]
    private SealedFile $sealed;

    #[ORM\Column(name: 'delete_after', type: Types::DATETIME_IMMUTABLE)]
    private DateTimeImmutable $deleteAfter;

    #[ORM\Column(name: 'uploaded_at', type: Types::DATETIME_IMMUTABLE)]
    private DateTimeImmutable $uploadedAt;

    /**
     * Die Kennung kommt herein und entsteht nicht hier.
     *
     * Sie geht als zusaetzliche Daten in die Verschluesselung ein, muss also
     * schon feststehen, wenn versiegelt wird — und das passiert, bevor es
     * diese Zeile gibt.
     */
    public function __construct(
        Message $message,
        string $id,
        string $name,
        string $mediaType,
        int $sizeBytes,
        SealedFile $sealed,
        DateTimeImmutable $uploadedAt,
        DateTimeImmutable $deleteAfter,
    ) {
        $this->id = $id;
        $this->message = $message;
        $this->name = mb_substr(trim($name), 0, 255);
        $this->mediaType = mb_substr($mediaType, 0, 120);
        $this->sizeBytes = $sizeBytes;
        $this->sealed = $sealed;
        $this->uploadedAt = $uploadedAt;
        $this->deleteAfter = $deleteAfter;

        $message->add($this);
    }

    public function id(): string
    {
        return $this->id;
    }

    public function message(): Message
    {
        return $this->message;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function mediaType(): string
    {
        return $this->mediaType;
    }

    public function sizeBytes(): int
    {
        return $this->sizeBytes;
    }

    public function sealed(): SealedFile
    {
        return $this->sealed;
    }

    public function deleteAfter(): DateTimeImmutable
    {
        return $this->deleteAfter;
    }

    /**
     * Abgelaufen — und das entscheidet schon die Auslieferung.
     *
     * Nicht erst der Aufraeumlauf: die Frist gilt ab der Sekunde und nicht
     * ab dem naechsten Durchgang. Ein Versprechen, das an einem Zeitplan
     * haengt, ist keines.
     */
    public function isExpired(DateTimeImmutable $on): bool
    {
        return $this->deleteAfter <= $on;
    }
}
