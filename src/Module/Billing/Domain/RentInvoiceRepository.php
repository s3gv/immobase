<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Billing\Domain;

use App\Shared\Ui\Page;

/**
 * Die Dauermietrechnungen — gespeichert und wiedergefunden.
 */
interface RentInvoiceRepository
{
    public function save(RentInvoice $invoice): void;

    /**
     * Mehrere Schreibvorgaenge als einer.
     *
     * Das Ausstellen schliesst die Vorgaengerin und friert die neue Fassung
     * ein. Bliebe davon die erste Haelfte stehen, waere die Vorgaengerin
     * beendet, ohne dass eine Nachfolgerin gilt — genau die Luecke, die das
     * Schliessen verhindern soll.
     *
     * @template T
     *
     * @param callable(): T $work
     *
     * @return T
     */
    public function atomically(callable $work): mixed;

    public function remove(RentInvoice $invoice): void;

    public function byId(string $id): ?RentInvoice;

    /** Die naechste Fassungsnummer dieses Mietverhaeltnisses, ab eins. */
    public function nextNumberFor(string $tenancyId): int;

    public function countMatching(RentInvoiceFilter $filter): int;

    /**
     * @return list<RentInvoice>
     */
    public function matching(RentInvoiceFilter $filter, Page $page): array;

    /**
     * Alle Fassungen eines Mietverhaeltnisses, die aelteste zuerst.
     *
     * @return list<RentInvoice>
     */
    public function forTenancy(string $tenancyId): array;

    /**
     * Die zuletzt ausgestellte Fassung eines Mietverhaeltnisses.
     *
     * Sie ist die Vorgaengerin einer Folgefassung — **abgeleitet und nicht
     * verwiesen**: `Edition::correctsId` gehoert der Berichtigung, und
     * „berichtigt" und „folgt auf" sind zwei Beziehungen.
     */
    public function lastIssuedFor(string $tenancyId): ?RentInvoice;

    /**
     * Welche dieser Fassungen inzwischen berichtigt sind.
     *
     * Eine Berichtigung tritt an die Stelle der Fassung, die sie berichtigt,
     * und traegt deren Zeitraum. Beide stehen damit mit demselben Zeitraum in
     * der Liste — welche gilt, muss daranstehen.
     *
     * Gezaehlt werden nur **ausgestellte** Berichtigungen. Ein Entwurf liegt
     * hier und nicht beim Mieter; bis er hinausgeht, ist das Original die
     * gueltige Rechnung.
     *
     * @param list<string> $ids
     *
     * @return list<string> die Kennungen daraus, zu denen es eine ausgestellte Berichtigung gibt
     */
    public function correctedAmong(array $ids): array;

    /**
     * Zu welchen Mietverhaeltnissen ueberhaupt schon einmal ausgestellt wurde.
     *
     * Der Vorschlag meldet nur dort eine fehlende Fassung: wer nie eine
     * Dauermietrechnung geschrieben hat, will keine zweihundert Hinweise.
     *
     * @return list<string> Kennungen der Mietverhaeltnisse
     */
    public function tenanciesWithAnInvoice(): array;
}
