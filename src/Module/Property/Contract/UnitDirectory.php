<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Property\Contract;

/**
 * Einheiten nachschlagen und suchen — fuer fremde Module.
 *
 * Dieselbe Bauart wie PartyDirectory und aus demselben Grund: ohne sie
 * muesste das Mietmodul die Unit-Entity kennen, um eine Einheit auch nur mit
 * Namen anzuzeigen.
 */
interface UnitDirectory
{
    /**
     * Die Einheiten zu diesen Kennungen.
     *
     * Eine Menge und kein einzelner: eine Uebersicht mit fuenfzig
     * Mietverhaeltnissen zeigt fuenfzig Einheiten, und fuenfzig Abfragen sind
     * genau das Muster, das Listen langsam macht. Unbekannte Kennungen fehlen
     * im Ergebnis.
     *
     * @param list<string> $ids
     *
     * @return array<string, UnitBrief> Kennung auf Einheit
     */
    public function byIds(array $ids): array;

    /**
     * Suche fuer die Vervollstaendigung — ueber Objektname, Objektnummer und
     * Bezeichnung der Einheit.
     *
     * @return list<UnitBrief>
     */
    public function search(string $term, int $limit = 10): array;

    /**
     * Die Einheiten eines Objekts.
     *
     * Fuer den Objektfilter: „zeige mir die Mietverhaeltnisse dieses Hauses"
     * heisst „die seiner Einheiten".
     *
     * @return list<UnitBrief>
     */
    public function ofProperty(int $propertyNumber): array;

    /**
     * Alle Einheiten in Verwaltung, nach Objekt und Nummer.
     *
     * Fuer Listen, die je Einheit eine Zeile zeigen — die Vorauszahlungen
     * etwa. Abgegebene Einheiten stehen nicht darin: sie sind Geschichte,
     * und eine Vorauszahlung fuer etwas, das wir nicht mehr verwalten, gibt
     * es nicht.
     *
     * @return list<UnitBrief>
     */
    public function all(int $limit = 500): array;
}
