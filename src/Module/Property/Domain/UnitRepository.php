<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Property\Domain;

use App\Shared\Search\SearchTerm;
use App\Shared\Ui\Page;

/**
 * Zugriff auf die Einheiten.
 *
 * Eine Einheit gehoert immer zu einem Objekt; deshalb gibt es kein „finde
 * Einheit 3" ohne das Objekt dazu. Zwei Objekte haben beide eine Einheit 3.
 */
interface UnitRepository
{
    public function save(Unit $unit): void;

    public function remove(Unit $unit): void;

    /** Einen einzelnen Personeneintrag — nicht die ganze Einheit. */
    public function removeHousehold(UnitHousehold $step): void;

    public function byNumber(Property $property, int $number): ?Unit;

    /**
     * Wie viele Eigentuemerzuordnungen haengen an dieser Einheit?
     *
     * Fuer die Rueckfrage vor dem Loeschen: sie nennt die Folgen.
     */
    public function ownerCount(Unit $unit): int;

    /**
     * Die Einheiten zu diesen Kennungen — in einem Zug, nicht einzeln.
     *
     * @param list<string> $ids
     *
     * @return list<Unit>
     */
    public function byIds(array $ids): array;

    public function byId(string $id): ?Unit;

    /**
     * Die Einheiten, an denen diese Partei als Eigentuemerin steht.
     *
     * Auch die vergangenen: wer verkauft hat, war einmal Eigentuemer, und ein
     * Portal, das die Wohnung einfach verschwinden liesse, erweckte den
     * Eindruck, es habe sie nie gegeben.
     *
     * @return list<Unit> mit ihren Eigentuemern und Objekten
     */
    public function ownedBy(string $partyId): array;

    /**
     * Die Treffer der zentralen Suche ueber Einheiten.
     *
     * Wie {@see search()}, nur mit der Lage dazu — und ohne die
     * Einschraenkung auf verwaltete Objekte: wer eine abgegebene Einheit
     * sucht, sucht sie, weil er sie meint.
     *
     * @return list<Unit> mit ihren Objekten
     */
    public function anywhere(SearchTerm $term, int $limit): array;

    /**
     * Suche fuer die Vervollstaendigung: Objektname, Objektnummer,
     * Bezeichnung der Einheit.
     *
     * @return list<Unit>
     */
    public function search(string $term, int $limit): array;

    /**
     * Alle Einheiten in Verwaltung, nach Objekt und Nummer.
     *
     * Abgegebene stehen nicht darin — wie in der Suche und aus demselben
     * Grund: was wir nicht mehr verwalten, gehoert in keine laufende Liste.
     *
     * @return list<Unit>
     */
    public function all(int $limit): array;

    /**
     * Dieselbe Liste, aber seitenweise.
     *
     * Fuer die Schnittstelle: ein Plugin holt sich den Bestand in Portionen
     * ab, und `all()` mit einer grossen Grenze waere dafuer ein Abzug der
     * ganzen Tabelle in einem Zug.
     *
     * @return list<Unit>
     */
    public function pageOf(Page $page): array;

    /**
     * Wie viele Einheiten verwaltet werden.
     *
     * Ohne die abgegebenen: was nicht mehr verwaltet wird, steht in keiner
     * Abrechnung und in keinem Plan — eine Zahl, die es mitzaehlte, waere
     * groesser als die Arbeit dahinter.
     */
    public function countManaged(): int;
}
