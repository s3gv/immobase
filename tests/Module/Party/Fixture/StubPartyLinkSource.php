<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Tests\Module\Party\Fixture;

use App\Module\Party\Contract\PartyLinkSource;

/**
 * Ein Modul, das auf Stammdaten verweist — nur fuer Tests.
 *
 * Es gibt noch kein Fachmodul, das PartyLinkSource umsetzt. Ohne diese
 * Attrappe waere die ganze Sperre unbelegt: sie wuerde immer "keine
 * Verknuepfungen" antworten, und niemand wuesste, ob sie im Ernstfall greift.
 */
final class StubPartyLinkSource implements PartyLinkSource
{
    /** @var list<string> */
    public array $linkedIds = [];

    public function linksTo(array $partyIds): array
    {
        $links = [];

        foreach ($partyIds as $partyId) {
            if (\in_array($partyId, $this->linkedIds, true)) {
                $links[$partyId] = ['party.link.test'];
            }
        }

        return $links;
    }
}
