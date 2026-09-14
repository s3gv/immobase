<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Tenancy\Application;

use App\Module\Party\Contract\PartyDirectory;
use App\Module\Tenancy\Domain\Tenancy;
use App\Module\Tenancy\Domain\TenancyRepository;
use App\Module\Tenancy\Domain\Tenant;
use App\Module\Tenancy\Domain\UnknownTenant;

/**
 * Wer mietet.
 *
 * Das Formular schickt die Liste, wie sie danach aussehen soll. Abgeglichen
 * wird trotzdem Zeile fuer Zeile: alles wegzuwerfen und neu anzulegen waere
 * kuerzer und laeuft in den eindeutigen Index ueber (Mietverhaeltnis,
 * Kontakt) — beim Speichern liegt die neue Zeile fuer denselben Mieter vor
 * der geloeschten alten, und die Datenbank sagt nein. Dieselbe Falle wie bei
 * den Eigentuemern, und derselbe Ausweg.
 *
 * Vorher wird jede Kennung nachgeschlagen. Die letzte Grenze zieht die
 * Datenbank — tenancy_tenant.party_id verweist auf party —, aber sie zieht sie
 * erst beim Speichern und mit einem Fehler, den niemand lesen will.
 */
final readonly class AssignTenants
{
    public function __construct(
        private TenancyRepository $tenancies,
        private PartyDirectory $parties,
    ) {
    }

    /**
     * @param list<string> $partyIds
     *
     * @throws UnknownTenant
     */
    public function to(Tenancy $tenancy, array $partyIds): void
    {
        // Vor der ersten Aenderung: eine halb uebernommene Liste waere
        // schlimmer als eine abgelehnte.
        $this->refuseUnknown($partyIds);

        $before = self::byParty($tenancy);

        foreach ($partyIds as $partyId) {
            if (isset($before[$partyId])) {
                unset($before[$partyId]);

                continue;
            }

            new Tenant($tenancy, $partyId);
        }

        // Wer jetzt noch uebrig ist, stand nicht mehr im Formular.
        foreach ($before as $gone) {
            $tenancy->remove($gone);
        }

        $this->tenancies->save($tenancy);
    }

    /**
     * @param list<string> $partyIds
     *
     * @throws UnknownTenant
     */
    private function refuseUnknown(array $partyIds): void
    {
        $missing = array_values(array_diff($partyIds, array_keys($this->parties->byIds($partyIds))));

        if ([] !== $missing) {
            throw UnknownTenant::of($missing[0]);
        }
    }

    /**
     * @return array<string, Tenant>
     */
    private static function byParty(Tenancy $tenancy): array
    {
        $found = [];

        foreach ($tenancy->tenants() as $tenant) {
            $found[$tenant->partyId()] = $tenant;
        }

        return $found;
    }
}
