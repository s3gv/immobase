<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Tenancy\Contract;

use DateTimeImmutable;

/**
 * Was fremde Module ueber Mietverhaeltnisse erfahren.
 *
 * Gefragt wird nach einem Tag und nicht nach einem Status: welches
 * Mietverhaeltnis lief an diesem Datum in dieser Einheit — aktiv oder
 * beendet, das ist fuer die Abrechnung eines vergangenen Jahres dieselbe
 * Frage.
 *
 * Die Richtung ist Absicht: die Finanzen kennen die Miete, die Miete kennt
 * die Finanzen nicht. Andersherum waere es ein Zyklus.
 */
interface TenancyDirectory
{
    /**
     * Die Vorauszahlungen dieser Einheiten an diesem Tag.
     *
     * @param list<string> $unitIds
     *
     * @return array<string, UnitAdvance> Kennung der Einheit auf ihre Vorauszahlung
     */
    public function advancesFor(array $unitIds, DateTimeImmutable $on): array;

    /**
     * Ein Mietverhaeltnis als Ganzes — fuer die Dauermietrechnung.
     *
     * Null heisst: es gibt keines mit dieser Kennung, oder es ist noch ein
     * Entwurf. Ueber einen Vertrag, der nicht in Kraft ist, laesst sich keine
     * Rechnung stellen.
     */
    public function brief(string $tenancyId): ?TenancyBrief;

    /**
     * Alle Mietverhaeltnisse, ueber die sich abrechnen laesst.
     *
     * Entwuerfe fehlen. Beendete stehen dabei: die Dauermietrechnung eines
     * abgelaufenen Vertrags bleibt ein Beleg, und wer sie nachtraeglich
     * braucht, findet den Vertrag sonst nicht mehr.
     *
     * @return list<TenancyBrief>
     */
    public function lettable(): array;

    /**
     * Was diese Partei gemietet hat — auch, was sie gemietet hatte.
     *
     * Die andere Richtung von {@see lettable()}, und sie hat einen eigenen
     * Aufrufer: das Portal zeigt einem Mieter seine Vertraege. Es fragt
     * **ausdruecklich nicht** nach allen und filtert danach — eine Methode,
     * die alles liefert, ist die Stelle, an der der Filter eines Tages
     * wegfaellt.
     *
     * Entwuerfe bleiben draussen: ein Vertrag, der noch nicht in Kraft ist,
     * geht den Mieter noch nichts an.
     *
     * @return list<TenancyBrief> das laufende zuerst, danach die beendeten
     */
    public function rentedBy(string $partyId): array;
}
