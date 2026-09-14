<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Party\Domain;

use App\Shared\Search\SearchTerm;
use App\Shared\Ui\Page;

interface PartyRepository
{
    public function save(Party $party): void;

    public function remove(Party $party): void;

    /**
     * Mehrere Schreibvorgaenge als einer.
     *
     * Loeschen heisst: das Zugehoerige wegraeumen und dann die Partei. Bliebe
     * davon die Haelfte stehen, stuenden Gespraeche in der Welt, deren
     * Gegenueber es nicht mehr gibt.
     *
     * @template T
     *
     * @param callable(): T $work
     *
     * @return T
     */
    public function atomically(callable $work): mixed;

    public function byId(string $id): ?Party;

    public function byReference(int $reference): ?Party;

    /**
     * Die naechste freie Referenznummer.
     *
     * Durchlaufend fuer alle Rollen, beginnend bei 10001 — eine fuenfstellige
     * Nummer sieht nach Kennung aus und nicht nach Zaehlerstand.
     */
    public function nextReference(): int;

    public function countMatching(PartyFilter $filter): int;

    /**
     * @return list<Party>
     */
    public function matching(PartyFilter $filter, Page $page): array;

    /**
     * Die ersten Treffer zu einem Suchbegriff — fuer die Vervollstaendigung.
     *
     * Eigene Methode und keine Seite ueber matching(): dort steht ein Filter
     * mit Rolle und Status, hier zaehlt nur, dass die Liste kurz ist und
     * schnell kommt.
     *
     * @return list<Party>
     */
    public function search(string $term, int $limit): array;

    /**
     * Die Treffer der zentralen Suche — ueber alles, was an einer Partei steht.
     *
     * Nicht {@see search()}: das ist die kurze Liste der Vervollstaendigung
     * und sieht nur auf Name und Nummer. Hier zaehlt, dass jemand den Kontakt
     * ueber die Strasse, die Postleitzahl, seine Mailadresse oder die
     * Steuernummer wiederfindet — er hat die Nummer selten im Kopf.
     *
     * @return list<Party>
     */
    public function anywhere(SearchTerm $term, int $limit): array;

    /**
     * @param list<string> $ids
     *
     * @return list<Party>
     */
    public function byIds(array $ids): array;
}
