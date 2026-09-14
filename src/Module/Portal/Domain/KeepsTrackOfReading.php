<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Portal\Domain;

use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Wer bis wann gelesen hat — und wann darueber eine Mail hinausgeht.
 *
 * Getrennt von {@see Enquiry}: dort steht, worum es geht und wer fragt; hier steht, wer es
 * schon gesehen hat. Das ist eine andere Frage mit eigenen Regeln, und es
 * sind vier Zeitpunkte, die nur miteinander Sinn ergeben.
 *
 * **Gelesen wird je Seite getrennt gefuehrt.** Ungelesen heisst: die letzte
 * Nachricht ist juenger als der eigene Lesezeitpunkt. Daraus kommen beide
 * Abzeichen und der Kachelwert „Ungelesen".
 *
 * **Die Regel gegen das Spam-Werkzeug** steht ebenfalls hier: hoechstens eine
 * Mail, bis gelesen wurde.
 */
trait KeepsTrackOfReading
{
    #[ORM\Column(name: 'read_by_staff_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?DateTimeImmutable $readByStaffAt = null;

    #[ORM\Column(name: 'read_by_party_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?DateTimeImmutable $readByPartyAt = null;

    /** Der anstehende Termin fuer die Benachrichtigung — hoechstens einer. */
    #[ORM\Column(name: 'notify_due_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?DateTimeImmutable $notifyDueAt = null;

    /** Schon benachrichtigt und noch nicht gelesen — dann kommt keine zweite. */
    #[ORM\Column(name: 'notified_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?DateTimeImmutable $notifiedAt = null;

    public function notifyDueAt(): ?DateTimeImmutable
    {
        return $this->notifyDueAt;
    }

    /**
     * Einen Termin fuer die Benachrichtigung setzen — wenn keiner ansteht.
     *
     * Ein anstehender Termin wird von weiteren Nachrichten nicht verschoben,
     * sonst kaeme bei fortlaufendem Schreiben nie eine Mail. Und nach einer
     * verschickten kommt keine zweite, solange nicht gelesen wurde — sonst
     * waere es weiter ein Spam-Werkzeug, nur mit zehn Minuten Takt.
     */
    public function notifyAt(DateTimeImmutable $due): void
    {
        if (null === $this->notifyDueAt && null === $this->notifiedAt) {
            $this->notifyDueAt = $due;
        }
    }

    public function notificationSentAt(DateTimeImmutable $at): void
    {
        $this->notifiedAt = $at;
        $this->notifyDueAt = null;
    }

    public function readByStaff(DateTimeImmutable $at): void
    {
        $this->readByStaffAt = $at;
    }

    /** Gelesen heisst: der Zyklus faengt von vorn an. */
    public function readByParty(DateTimeImmutable $at): void
    {
        $this->readByPartyAt = $at;
        $this->notifyDueAt = null;
        $this->notifiedAt = null;
    }

    public function isUnreadByStaff(): bool
    {
        return null === $this->readByStaffAt || $this->readByStaffAt < $this->lastMessageAt();
    }

    public function isUnreadByParty(): bool
    {
        return null === $this->readByPartyAt || $this->readByPartyAt < $this->lastMessageAt();
    }
}
