<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Tests\Module\Property\Fixture;

use App\Module\Party\Contract\PartyBrief;
use App\Module\Party\Contract\PartyDetails;
use App\Module\Party\Contract\PartyDirectory;

/**
 * Ein Stammdatenverzeichnis mit genau den Kennungen, die der Test kennt.
 *
 * Alles andere ist unbekannt — und darum geht es hier: die Anwendung schlaegt
 * nach, statt die Kennung aus dem Formular zu glauben.
 */
final readonly class KnownParties implements PartyDirectory
{
    /** @var list<string> */
    private array $ids;

    public function __construct(string ...$ids)
    {
        $this->ids = array_values($ids);
    }

    public function byIds(array $ids): array
    {
        $found = [];

        foreach (array_intersect($ids, $this->ids) as $id) {
            $found[$id] = new PartyBrief($id, 10001, 'Bekannt '.$id, 'Musterweg 1, 12345 Musterstadt');
        }

        return $found;
    }

    public function search(string $term, int $limit = 10): array
    {
        return array_values($this->byIds($this->ids));
    }

    public function detailsOf(string $partyId): ?PartyDetails
    {
        return null;
    }
}
