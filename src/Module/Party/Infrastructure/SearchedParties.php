<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Party\Infrastructure;

use App\Module\Party\Domain\Party;
use App\Shared\Search\SearchTerm;
use Doctrine\DBAL\Connection;

/**
 * Die Abfrage der zentralen Suche ueber die Stammdaten.
 *
 * Steht neben dem Repository und nicht darin: das Uebersetzen einer Frage in
 * eine Abfrage ist eine eigene Aufgabe.
 *
 * **Native Abfrage und nicht DQL.** Anschriften und Erreichbarkeiten liegen
 * als JSON in der Zeile; die Datenbank schreibt darin Umlaute als `ü`,
 * und ein LIKE auf den rohen Text faende „Duesseldorf" nie. `->>` und
 * `json_array_elements` geben den entpackten Text heraus, und darauf laesst
 * sich vergleichen.
 *
 * Das kostet einen vollen Durchlauf ueber die Tabelle. Fuer eine Verwaltung
 * mit einigen zehntausend Kontakten ist das die guenstigere Seite des
 * Handels: die Alternative waere eine zweite Tabelle mit Suchtext, die bei
 * jedem vergessenen Schreibpfad still veraltet.
 */
final readonly class SearchedParties
{
    private const array CONDITIONS = [
        'LOWER(p.sort_name) LIKE :text',
        'LOWER(p.tax_number) LIKE :text',
        "EXISTS (SELECT 1 FROM json_array_elements(p.addresses) a
            WHERE LOWER(a->>'line') LIKE :text OR LOWER(a->>'city') LIKE :text
               OR LOWER(a->>'postalCode') LIKE :text OR LOWER(a->>'addition') LIKE :text)",
        'EXISTS (SELECT 1 FROM json_array_elements_text(p.contact_emails) e WHERE LOWER(e) LIKE :text)',
        'EXISTS (SELECT 1 FROM json_array_elements_text(p.contact_phones) t WHERE LOWER(t) LIKE :text)',
    ];

    /**
     * Die Kennungen der Treffer, aktive zuerst.
     *
     * Kennungen und keine Zeilen: daraus macht das Repository Entitaeten, und
     * die Reihenfolge dieser Liste ist die Aussage darueber, was der beste
     * Treffer ist.
     *
     * @return list<string>
     */
    public static function of(Connection $connection, SearchTerm $term, int $limit): array
    {
        $conditions = self::CONDITIONS;
        $parameters = ['text' => $term->contains(), 'limit' => $limit];

        if ($term->isNumber()) {
            $conditions[] = 'p.reference = :reference';
            $parameters['reference'] = $term->number();
        }

        // Aktive zuerst: ein archivierter Kontakt ist selten der gemeinte.
        $found = $connection->fetchFirstColumn(
            'SELECT p.id FROM party p WHERE '.implode(' OR ', $conditions)
            .' ORDER BY p.status ASC, p.sort_name ASC LIMIT :limit',
            $parameters,
        );

        return array_values(array_filter($found, \is_string(...)));
    }

    /**
     * Die Entitaeten in der Reihenfolge ihrer Kennungen.
     *
     * `IN (…)` gibt keine Reihenfolge zu; die der Suche ist aber die
     * Aussage — sie sagt, was der beste Treffer ist.
     *
     * @param list<string> $ids
     * @param list<Party>  $found
     *
     * @return list<Party>
     */
    public static function inTheOrderOf(array $ids, array $found): array
    {
        $byId = [];

        foreach ($found as $party) {
            $byId[$party->id()] = $party;
        }

        $ordered = [];

        foreach ($ids as $id) {
            if (isset($byId[$id])) {
                $ordered[] = $byId[$id];
            }
        }

        return $ordered;
    }
}
