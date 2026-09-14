<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Shared\Search;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Was ein Modul durchsuchbar macht — eine Quelle je Art.
 *
 * Jedes Modul meldet sich selbst an, wie bei den Rechten, die jedes Modul
 * mitbringt. Die Suche zaehlt keine Module auf, sie fragt, wer
 * sich gemeldet hat.
 *
 * Die Schnittstelle liegt in Shared und nicht im Dashboard: **kein Modul
 * haengt vom Dashboard ab.** Andersherum waere die Uebersicht eine Stelle,
 * von der jedes Fachmodul abhinge.
 *
 * **Eine Quelle liefert genau eine Art.** Objekte und Einheiten sind zwei
 * Quellen, auch wenn sie im selben Modul liegen: sonst traegt jeder Treffer
 * seine Art mit sich, und „alle anzeigen" wuesste nicht, wohin.
 *
 * **Die Suche ist keine Hintertuer an den Rechten vorbei.** Jede Umsetzung
 * prueft ihr eigenes Recht und liefert sonst nichts. Nicht der Sammler: der
 * wuesste sonst, welches Recht zu welchem Modul gehoert, und das waere die
 * Stelle, an der eines vergessen wird.
 */
#[AutoconfigureTag('search.source')]
interface SearchesRecords
{
    /** Uebersetzungsschluessel der Art, etwa `search.kind.property`. */
    public function kindKey(): string;

    /**
     * Die Liste des Moduls mit demselben Text — wohin „alle anzeigen" fuehrt.
     *
     * Leer, wenn es keine Liste gibt, in der sich danach suchen laesst. Dann
     * steht der Weg auch nicht da: ein Link, der die Suche vergisst, ist
     * schlechter als keiner.
     */
    public function listUrl(SearchTerm $term): string;

    /**
     * Die besten Treffer dieser Art, hoechstens `$limit`.
     *
     * In eigener Reihenfolge: was ein guter Treffer ist, weiss das Modul.
     * Eine Rangfolge ueber Module hinweg gibt es nicht — sie waere geraten.
     *
     * @return list<SearchHit>
     */
    public function matching(SearchTerm $term, int $limit): array;
}
