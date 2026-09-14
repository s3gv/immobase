<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Party\Contract;

/**
 * Stammdaten nachschlagen und suchen — fuer fremde Module.
 *
 * Die zweite Flaeche des Party-Moduls neben PartyLinkSource, und die
 * Gegenrichtung: dort meldet ein Modul, was es haelt, hier fragt es nach.
 *
 * Ohne sie muesste das Objektmodul die Party-Entity kennen, um einen
 * Eigentuemer auch nur mit Namen anzuzeigen — und damit die Modulgrenze
 * einreissen, die es gerade erst zu tragen anfaengt.
 */
interface PartyDirectory
{
    /**
     * Die Datensaetze zu diesen Kennungen.
     *
     * Eine Menge und kein einzelner: eine Einheitenliste zeigt zwanzig
     * Eigentuemer, und zwanzig Abfragen sind genau das Muster, das Listen
     * langsam macht. Unbekannte Kennungen fehlen im Ergebnis.
     *
     * @param list<string> $ids
     *
     * @return array<string, PartyBrief> Kennung auf Datensatz
     */
    public function byIds(array $ids): array;

    /**
     * Suche fuer die Vervollstaendigung — ueber Name und Nummer.
     *
     * @return list<PartyBrief>
     */
    public function search(string $term, int $limit = 10): array;

    /**
     * Alles, was ueber diese Partei gespeichert ist — fuer sie selbst.
     *
     * Einzeln und nicht als Menge: gefragt wird von jemandem, der genau einen
     * Datensatz sehen darf, naemlich seinen eigenen. Eine Methode, die viele
     * liefert, waere hier die falsche Form — sie laedt dazu ein, zu viele zu
     * laden und danach zu filtern.
     */
    public function detailsOf(string $partyId): ?PartyDetails;
}
