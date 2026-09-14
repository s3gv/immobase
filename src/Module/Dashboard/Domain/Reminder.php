<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Dashboard\Domain;

use App\Shared\Identity\Uuid;
use App\Shared\Text\Trimmed;
use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Eine Erinnerung auf dem Zeitstrahl — an einen Tag, zu einer Uhrzeit.
 *
 * **Sie gehoert einem Menschen und keinem Datensatz.** Was wichtig ist, weiss
 * die Anwendung nicht: der eine will an den Rueckruf beim Handwerker erinnert
 * werden, der naechste an die Eigentuemerversammlung. Eine Erinnerung, die
 * aus einem Feld abgeleitet waere, traefe immer nur die Faelle, an die beim
 * Bauen jemand gedacht hat.
 *
 * Darum haengt sie am Benutzer und nicht an Objekt, Einheit oder Partei — und
 * darum steht in der Notiz, was sie betrifft. Wer eine Zuordnung braucht,
 * schreibt sie hinein; sie zu erzwingen hiesse, eine Erinnerung an das
 * Zahnarztjahr der Verwalterin zu verbieten.
 *
 * Sie liegt im Dashboard-Modul, weil sie nur dort erscheint: auf dem
 * Zeitstrahl der Uebersicht. Ein eigenes Modul waere eines mit einer Tabelle,
 * einem Formular und keinem zweiten Leser.
 */
#[ORM\Entity]
#[ORM\Table(name: 'dashboard_reminder')]
// Gelesen wird immer dasselbe: die Erinnerungen eines Menschen in einem
// Zeitraum. Ein Index auf beides zusammen beantwortet genau diese Frage.
#[ORM\Index(name: 'dashboard_reminder_owner', columns: ['user_id', 'due_at'])]
class Reminder
{
    /** Laenger traegt keine Zeile des Zeitstrahls, und laenger liest auch niemand. */
    public const int SUBJECT_LIMIT = 120;

    public const int NOTE_LIMIT = 400;

    #[ORM\Id]
    #[ORM\Column(type: Types::GUID)]
    private string $id;

    #[ORM\Column(name: 'user_id', type: Types::GUID)]
    private string $userId;

    #[ORM\Column(type: Types::STRING, length: self::SUBJECT_LIMIT)]
    private string $subject;

    #[ORM\Column(type: Types::STRING, length: self::NOTE_LIMIT)]
    private string $note;

    #[ORM\Column(name: 'due_at', type: Types::DATETIME_IMMUTABLE)]
    private DateTimeImmutable $dueAt;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    private DateTimeImmutable $createdAt;

    public function __construct(string $userId, string $subject, string $note, DateTimeImmutable $dueAt)
    {
        $this->id = Uuid::v4();
        $this->userId = $userId;
        $this->subject = mb_substr(Trimmed::required($subject, 'Betreff'), 0, self::SUBJECT_LIMIT);
        $this->note = mb_substr(trim($note), 0, self::NOTE_LIMIT);
        $this->dueAt = $dueAt;
        $this->createdAt = new DateTimeImmutable();
    }

    public function id(): string
    {
        return $this->id;
    }

    public function subject(): string
    {
        return $this->subject;
    }

    public function note(): string
    {
        return $this->note;
    }

    public function dueAt(): DateTimeImmutable
    {
        return $this->dueAt;
    }

    /**
     * Gehoert sie diesem Menschen?
     *
     * Gefragt wird vor dem Loeschen. Die Abfrage holt ohnehin nur die eigenen
     * — aber eine Kennung aus der Adresszeile ist Eingabe, und Eingabe wird
     * geprueft und nicht geglaubt.
     */
    public function belongsTo(string $userId): bool
    {
        return $this->userId === $userId;
    }
}
