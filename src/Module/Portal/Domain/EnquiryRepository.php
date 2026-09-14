<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Portal\Domain;

use App\Shared\Ui\Page;
use DateTimeImmutable;

/**
 * Die Anfragen — gespeichert und wiedergefunden.
 */
interface EnquiryRepository
{
    public function save(Enquiry $enquiry): void;

    public function byId(string $id): ?Enquiry;

    /** Die naechste Nummer, ab eins. */
    public function nextNumber(): int;

    /**
     * Die Anfragen einer Partei — die juengste zuerst.
     *
     * Ausdruecklich mit Parteikennung und nicht als allgemeine Liste mit
     * Filter: das Portal soll gar nicht erst in der Lage sein, fremde
     * Gespraeche zu laden.
     *
     * @return list<Enquiry>
     */
    public function forParty(string $partyId): array;

    public function countMatching(EnquiryFilter $filter): int;

    /** @return list<Enquiry> */
    public function matching(EnquiryFilter $filter, Page $page): array;

    /** Wie viele Anfragen auf die Verwaltung warten — fuer das Abzeichen. */
    public function countUnreadByStaff(): int;

    /**
     * Die Zahlen der Kacheln in einer Abfrage.
     *
     * Drei Werte und drei Abfragen waeren drei Wege in dieselbe Tabelle fuer
     * eine Zeile Zahlen ueber der Liste.
     *
     * @return array{open: int, unread: int, answeredSeconds: int|null}
     */
    public function tallyOf(DateTimeImmutable $since): array;

    /**
     * Die Anfragen, deren Benachrichtigung faellig und noch ungelesen ist.
     *
     * @return list<Enquiry>
     */
    public function withNotificationDue(DateTimeImmutable $on): array;

    /**
     * Alles einer Partei wegraeumen — Gespraeche, Nachrichten, Anhaenge.
     *
     * Ein Gespraech ohne Gegenueber ist Aktenmuell.
     */
    public function discardFor(string $partyId): void;
}
