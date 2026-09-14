<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Party\Application;

use App\Module\Party\Contract\PartyBrief;
use App\Module\Party\Contract\PartyDetails;
use App\Module\Party\Contract\PartyDirectory;
use App\Module\Party\Domain\Party;
use App\Module\Party\Domain\PartyKind;
use App\Module\Party\Domain\PartyRepository;
use App\Module\Party\Domain\PostalAddress;

/**
 * Was fremde Module ueber Stammdaten erfahren.
 *
 * Die Umsetzung von PartyDirectory. Sie uebersetzt die Entity in das
 * schmale Wertobjekt des Contracts — mehr sieht draussen niemand.
 */
final readonly class LookupParties implements PartyDirectory
{
    private const int MAX_RESULTS = 25;

    public function __construct(private PartyRepository $parties)
    {
    }

    public function byIds(array $ids): array
    {
        $found = [];

        foreach ($this->parties->byIds($ids) as $party) {
            $found[$party->id()] = self::brief($party);
        }

        return $found;
    }

    public function search(string $term, int $limit = 10): array
    {
        $trimmed = trim($term);

        // Ohne Eingabe keine Liste: die ersten zehn Kontakte ohne Bezug zur
        // Frage sind keine Hilfe, sondern eine Falle fuer den schnellen Klick.
        if ('' === $trimmed) {
            return [];
        }

        return array_map(
            self::brief(...),
            $this->parties->search($trimmed, min($limit, self::MAX_RESULTS)),
        );
    }

    public function detailsOf(string $partyId): ?PartyDetails
    {
        $party = $this->parties->byIds([$partyId])[0] ?? null;

        return $party instanceof Party ? self::details($party) : null;
    }

    private static function details(Party $party): PartyDetails
    {
        return new PartyDetails(
            id: $party->id(),
            reference: $party->reference(),
            displayName: $party->displayName(),
            kind: $party->kind()->value,
            addresses: array_map(
                static fn (PostalAddress $address): string => $address->readable(),
                $party->addresses()->all(),
            ),
            emails: $party->contact()->emails(),
            phones: $party->contact()->phones(),
            taxNumber: $party->taxId()->toString(),
        );
    }

    private static function brief(Party $party): PartyBrief
    {
        return new PartyBrief(
            id: $party->id(),
            reference: $party->reference(),
            displayName: $party->displayName(),
            address: $party->addresses()->primary()->oneLine(),
            taxNumber: $party->taxId()->toString(),
            kind: $party->kind()->value,
            postalLines: self::postalLines($party),
            postal: [
                'line' => $party->addresses()->primary()->line,
                'postalCode' => $party->addresses()->primary()->postalCode,
                'city' => $party->addresses()->primary()->city,
            ],
        );
    }

    /**
     * Was im Anschriftfeld unter dem Namen steht.
     *
     * Die Zusatzzeile kann zweierlei tragen, und beides zugleich: bei einer
     * Firma den Ansprechpartner, bei jedem den Zusatz der Anschrift — „c/o",
     * ein Gebaeude, ein Stockwerk. Danach die Strasse (oder das Postfach) und
     * zuletzt Postleitzahl und Ort in einer Zeile; sie gehoeren zusammen und
     * werden nie getrennt.
     *
     * @return list<string>
     */
    private static function postalLines(Party $party): array
    {
        $address = $party->addresses()->primary();
        $contact = PartyKind::Company === $party->kind() ? $party->givenName() : null;

        $lines = [
            null === $contact ? '' : 'z. Hd. '.$contact,
            $address->addition,
            $address->line,
            $address->postalCode.' '.$address->city,
        ];

        return array_values(array_filter($lines, static fn (string $line): bool => '' !== trim($line)));
    }
}
