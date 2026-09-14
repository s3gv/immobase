<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Billing\Application;

use App\Module\Party\Contract\PartyBrief;
use App\Module\Party\Contract\PartyDirectory;

/**
 * Wie mehrere Menschen zu einem Anschriftfeld werden.
 *
 * Alle Namen in der Namenszeile, die Anschrift des ersten. Ein Ehepaar wohnt
 * an einer Anschrift; eine Erbengemeinschaft nicht unbedingt. Fuer den
 * Regelfall ist das richtig, und der Sonderfall gehoert nicht in diese
 * Ausgabe — ein Brief an drei Anschriften ist dreimal Post und nicht ein
 * Schreiben.
 *
 * An einer Stelle, weil Abrechnung und Wirtschaftsplan dieselbe Frage haben.
 * Zwei Antworten darauf hiessen, dass derselbe Eigentuemer auf zwei Schreiben
 * derselben Verwaltung verschieden heisst.
 */
final readonly class Addressed
{
    public function __construct(private PartyDirectory $parties)
    {
    }

    /**
     * @param list<string> $partyIds
     *
     * @return array{label: string, address: string}|null null, wenn niemand dasteht
     */
    public function of(array $partyIds): ?array
    {
        if ([] === $partyIds) {
            return null;
        }

        $known = $this->parties->byIds($partyIds);
        $found = array_values(array_filter(array_map(
            static fn (string $id): ?PartyBrief => $known[$id] ?? null,
            $partyIds,
        )));

        if ([] === $found) {
            return null;
        }

        return [
            'label' => implode(' und ', array_map(static fn (PartyBrief $p): string => $p->displayName, $found)),
            // Mehrzeilig, wie es ins Anschriftfeld gehoert: Zusatz, Strasse,
            // dann Postleitzahl und Ort. Die einzeilige Form ist fuer Listen.
            'address' => implode("\n", $found[0]->postalLines),
        ];
    }

    /**
     * Dasselbe, plus die Steuernummer — fuer den Aussteller einer Rechnung.
     *
     * § 14 Abs. 4 Nr. 2 UStG verlangt die Nummer des leistenden
     * Unternehmers. Gehoert die Einheit mehreren, gilt dieselbe Regel wie
     * fuer die Anschrift: die der ersten. Eine Rechnung mit drei
     * Steuernummern waere keine.
     *
     * @param list<string> $partyIds
     *
     * @return array{label: string, address: string, taxNumber: string}|null
     */
    public function asLandlord(array $partyIds): ?array
    {
        $found = $this->of($partyIds);

        if (null === $found) {
            return null;
        }

        $known = $this->parties->byIds($partyIds);

        foreach ($partyIds as $partyId) {
            if (isset($known[$partyId])) {
                return [...$found, 'taxNumber' => $known[$partyId]->taxNumber];
            }
        }

        return [...$found, 'taxNumber' => ''];
    }
}
