<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Property\Domain;

use App\Shared\Search\SearchTerm;
use App\Shared\Ui\Page;

/**
 * Zugriff auf die Objekte.
 *
 * Die Schnittstelle liegt in Domain, die Doctrine-Umsetzung in
 * Infrastructure. Kein anderes Modul darf beides benutzen — dafuer gibt es
 * Contract.
 */
interface PropertyRepository
{
    public function save(Property $property): void;

    /**
     * Alles oder nichts.
     *
     * Eine Abwicklung greift ueber Modulgrenzen durch: das Objekt, seine
     * Einheiten, die Mietverhaeltnisse daran. Ein beendetes Objekt mit
     * laufenden Mietverhaeltnissen waere schlimmer als der Zustand vorher —
     * scheitert einer der Schritte, darf keiner stehen bleiben.
     *
     * @template T
     *
     * @param callable(): T $work
     *
     * @return T
     */
    public function atomically(callable $work): mixed;

    public function remove(Property $property): void;

    public function byId(string $id): ?Property;

    public function byNumber(int $number): ?Property;

    /**
     * Die naechste freie Objektnummer, ab 20001.
     *
     * Aus einer Sequenz und nicht aus MAX(number) + 1: zwei gleichzeitige
     * Anlagen lesen sonst denselben Hoechststand, und die zweite laeuft in
     * den eindeutigen Index.
     */
    public function nextNumber(): int;

    /**
     * Die Treffer der zentralen Suche — ueber alles, was an einem Objekt steht.
     *
     * Auch ueber die Bankverbindung: wer einen Kontoauszug vor sich hat, hat
     * die IBAN und nicht die Objektnummer.
     *
     * @return list<Property>
     */
    public function anywhere(SearchTerm $term, int $limit): array;

    public function countMatching(PropertyFilter $filter): int;

    /**
     * Wie viele verwaltete Objekte ohne Bankverbindung dastehen.
     *
     * Ohne sie steht auf keinem Schreiben ein Konto, und keine Lastschrift
     * laesst sich einziehen. Es faellt niemandem auf, bis die erste
     * Abrechnung herausgeht — deshalb steht es auf der Uebersicht.
     */
    public function countWithoutAnAccount(): int;

    /**
     * @return list<Property>
     */
    public function matching(PropertyFilter $filter, Page $page): array;

    /**
     * Wie viele Einheiten haengen an welchem Objekt?
     *
     * Alle auf einmal und nicht je Objekt einzeln: die Uebersicht zeigt die
     * Zahl in jeder Zeile, und eine Abfrage je Zeile ist genau das Muster,
     * das Listen langsam macht.
     *
     * @param list<string> $propertyIds
     *
     * @return array<string, int>
     */
    public function unitCounts(array $propertyIds): array;

    /**
     * Die Kennungen der Stammdatensaetze, die irgendeine Einheit besitzen.
     *
     * Fuer die Loeschsperre der Stammdaten — gefragt wird fuer eine ganze
     * Seite auf einmal.
     *
     * @param list<string> $partyIds
     *
     * @return list<string> die Teilmenge, die Eigentum haelt
     */
    public function owningParties(array $partyIds): array;
}
