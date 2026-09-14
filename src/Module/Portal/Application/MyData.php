<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Portal\Application;

use App\Module\Party\Contract\PartyDetails;
use App\Module\Party\Contract\PartyDirectory;
use App\Module\Property\Contract\OwnedUnit;
use App\Module\Property\Contract\UnitOwnership;
use App\Module\Tenancy\Contract\TenancyBrief;
use App\Module\Tenancy\Contract\TenancyDirectory;
use DateTimeImmutable;

/**
 * Was dem Angemeldeten gehoert — und sonst nichts.
 *
 * Jede Methode holt sich die Partei bei {@see PortalIdentity} und reicht sie
 * an einen Vertrag weiter, der **nur dazu Passendes** liefert. Es gibt hier
 * keinen Aufruf, der alles holt und danach filtert: das waere die Stelle, an
 * der der Filter eines Tages wegfaellt und jemand die Wohnungen der Nachbarn
 * sieht.
 */
final readonly class MyData
{
    public function __construct(
        private PortalIdentity $who,
        private PartyDirectory $parties,
        private UnitOwnership $ownership,
        private TenancyDirectory $tenancies,
    ) {
    }

    public function party(): ?PartyDetails
    {
        return $this->parties->detailsOf($this->who->partyId());
    }

    /**
     * Die Einheiten, die ihm gehoeren — auch die verkauften.
     *
     * @return list<OwnedUnit>
     */
    public function ownedUnits(): array
    {
        return $this->ownership->ownedBy($this->who->partyId());
    }

    /**
     * Die Objekte, in denen ihm etwas gehoert.
     *
     * Abgeleitet aus den Einheiten und nicht eigens abgefragt: ein Objekt
     * gehoert niemandem, es gehoert eine Einheit darin. Wer nichts besitzt,
     * bekommt hier eine leere Liste und im Portal einen Hinweis statt einer
     * leeren Tabelle.
     *
     * @return list<array{id: string, number: int, name: string, address: string, units: int}>
     */
    public function properties(): array
    {
        $found = [];

        foreach ($this->ownedUnits() as $owned) {
            $id = $owned->unit->propertyId;
            $found[$id] ??= [
                'id' => $id,
                'number' => $owned->unit->propertyNumber,
                'name' => $owned->unit->propertyName,
                'address' => $owned->unit->address,
                'units' => 0,
            ];
            ++$found[$id]['units'];
        }

        return array_values($found);
    }

    /**
     * Die Mietverhaeltnisse, in denen er Mieter ist.
     *
     * @return list<TenancyBrief>
     */
    public function tenancies(): array
    {
        return $this->tenancies->rentedBy($this->who->partyId());
    }

    /**
     * Ist er heute Eigentuemer von irgendetwas?
     *
     * Entscheidet, ob die Bereiche „Meine Objekte" und „Meine Einheiten"
     * ueberhaupt etwas zu sagen haben. Wer nie einer war, soll keine zwei
     * leeren Seiten durchklicken.
     */
    public function isAnOwner(): bool
    {
        return [] !== $this->ownedUnits();
    }

    public function isATenant(): bool
    {
        return [] !== $this->tenancies();
    }

    public function today(): DateTimeImmutable
    {
        return new DateTimeImmutable('today');
    }
}
