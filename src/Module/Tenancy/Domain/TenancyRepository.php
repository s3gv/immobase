<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Tenancy\Domain;

use App\Shared\Ui\Page;
use App\Shared\Ui\Sort;
use DateTimeImmutable;

/**
 * Zugriff auf die Mietverhaeltnisse.
 *
 * Die Schnittstelle liegt in Domain, die Doctrine-Umsetzung in
 * Infrastructure. Kein anderes Modul darf beides benutzen — dafuer gibt es
 * Contract.
 */
interface TenancyRepository
{
    public function save(Tenancy $tenancy): void;

    public function remove(Tenancy $tenancy): void;

    public function byId(string $id): ?Tenancy;

    public function byNumber(int $number): ?Tenancy;

    /**
     * Die naechste freie Nummer, ab 30001.
     *
     * Aus einer Sequenz und nicht aus MAX(number) + 1: zwei gleichzeitige
     * Anlagen lesen sonst denselben Hoechststand, und die zweite laeuft in
     * den eindeutigen Index.
     */
    public function nextNumber(): int;

    /**
     * Die Mietverhaeltnisse zu diesen Kennungen — in einem Zug, nicht einzeln.
     *
     * @param list<string> $ids
     *
     * @return list<Tenancy>
     */
    public function byIds(array $ids): array;

    public function countMatching(TenancyFilter $filter): int;

    /**
     * Wie viele laufende Mietverhaeltnisse bis zu diesem Tag enden.
     *
     * Ein endendes Mietverhaeltnis zieht Arbeit nach sich — Abnahme,
     * Kaution, Nachmieter —, und die faengt nicht am letzten Tag an.
     */
    public function countEndingBy(DateTimeImmutable $day): int;

    /**
     * @return list<Tenancy>
     */
    public function matching(TenancyFilter $filter, Page $page, Sort $sort): array;

    /**
     * Das aktive Mietverhaeltnis dieser Einheit — oder keins.
     *
     * `$except` laesst eines aus: beim Bearbeiten ist das eigene kein
     * Hindernis fuer sich selbst.
     */
    public function activeFor(string $unitId, ?string $exceptTenancyId = null): ?Tenancy;

    /**
     * Ein Mietverhaeltnis derselben Einheit, dessen Laufzeit sich mit dieser
     * ueberschneidet — oder keins.
     *
     * Entwuerfe zaehlen nicht: ein laufendes Mietverhaeltnis ist meist
     * unbefristet und reicht damit bis in alle Zukunft. Waeren Entwuerfe
     * dabei, liesse sich nie ein Nachmieter vorab erfassen.
     */
    public function overlapping(
        string $unitId,
        DateTimeImmutable $from,
        ?DateTimeImmutable $to,
        ?string $exceptTenancyId = null,
    ): ?Tenancy;

    /**
     * Alle Mietverhaeltnisse dieser Einheiten, aktive zuerst.
     *
     * Fuer die Verweise auf der Einheitenseite — gefragt wird fuer eine ganze
     * Seite auf einmal, nicht je Einheit.
     *
     * @param list<string> $unitIds
     *
     * @return array<string, list<Tenancy>> Kennung der Einheit auf ihre Mietverhaeltnisse
     */
    public function forUnits(array $unitIds): array;

    /**
     * Die Kennungen der Stammdatensaetze, die irgendwo Mieter sind.
     *
     * Fuer die Loeschsperre der Stammdaten — gefragt wird fuer eine ganze
     * Seite auf einmal.
     *
     * @param list<string> $partyIds
     *
     * @return list<string> die Teilmenge, die mietet
     */
    public function rentingParties(array $partyIds): array;

    /**
     * Die Mietverhaeltnisse dieser Partei — ohne Entwuerfe.
     *
     * @return list<Tenancy> das juengste zuerst
     */
    public function rentedBy(string $partyId): array;
}
