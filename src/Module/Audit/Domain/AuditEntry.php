<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Audit\Domain;

use App\Shared\Audit\AuditAction;
use App\Shared\Identity\Uuid;
use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Eine Zeile im Aenderungsprotokoll.
 *
 * **Alles darin ist eingefroren.** Der Name des Handelnden steht als Text und
 * nicht als Verweis: ein Protokoll, dessen Eintraege unlesbar werden, sobald
 * ein Konto geloescht wird, protokolliert das Gegenteil von dem, wozu es da
 * ist. Dasselbe gilt fuer die Bezeichnung des Datensatzes — was geloescht
 * wurde, laesst sich nicht mehr nachschlagen, und gerade das ist der Fall,
 * fuer den es das Protokoll gibt.
 *
 * Die Kennung bleibt trotzdem stehen: sie ist der Faden, an dem sich zwei
 * Zeilen zu demselben Datensatz erkennen lassen, auch wenn er nicht mehr da
 * ist.
 */
#[ORM\Entity]
#[ORM\Table(name: 'audit_entry')]
// Gelesen wird immer dasselbe: die letzten Stunden, die juengsten zuerst.
#[ORM\Index(name: 'audit_entry_when', columns: ['at'])]
class AuditEntry
{
    public const int ACTOR_LIMIT = 200;
    public const int LABEL_LIMIT = 300;

    #[ORM\Id]
    #[ORM\Column(type: Types::GUID)]
    private string $id;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private DateTimeImmutable $at;

    #[ORM\Column(type: Types::STRING, length: 16, enumType: AuditAction::class)]
    private AuditAction $action;

    /** Wer — eingefroren, damit die Zeile ein geloeschtes Konto ueberlebt. */
    #[ORM\Column(type: Types::STRING, length: self::ACTOR_LIMIT)]
    private string $actor;

    /** „staff", „portal" oder „unknown" — siehe {@see ActorKind}. */
    #[ORM\Column(name: 'actor_kind', type: Types::STRING, length: 16, enumType: ActorKind::class)]
    private ActorKind $actorKind;

    /** Die Art des Datensatzes, ohne Namensraum: „Party", „Statement". */
    #[ORM\Column(type: Types::STRING, length: 64)]
    private string $record;

    #[ORM\Column(name: 'record_id', type: Types::STRING, length: 64)]
    private string $recordId;

    /** Woran man ihn erkennt — eingefroren, aus demselben Grund wie der Name. */
    #[ORM\Column(type: Types::STRING, length: self::LABEL_LIMIT)]
    private string $label;

    public function __construct(
        DateTimeImmutable $at,
        AuditAction $action,
        string $actor,
        ActorKind $actorKind,
        string $record = '',
        string $recordId = '',
        string $label = '',
    ) {
        $this->id = Uuid::v4();
        $this->at = $at;
        $this->action = $action;
        $this->actor = mb_substr($actor, 0, self::ACTOR_LIMIT);
        $this->actorKind = $actorKind;
        $this->record = mb_substr($record, 0, 64);
        $this->recordId = mb_substr($recordId, 0, 64);
        $this->label = mb_substr($label, 0, self::LABEL_LIMIT);
    }

    public function id(): string
    {
        return $this->id;
    }

    public function at(): DateTimeImmutable
    {
        return $this->at;
    }

    public function action(): AuditAction
    {
        return $this->action;
    }

    public function actor(): string
    {
        return $this->actor;
    }

    public function actorKind(): ActorKind
    {
        return $this->actorKind;
    }

    public function record(): string
    {
        return $this->record;
    }

    public function recordId(): string
    {
        return $this->recordId;
    }

    public function label(): string
    {
        return $this->label;
    }
}
