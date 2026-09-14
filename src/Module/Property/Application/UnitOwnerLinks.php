<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Property\Application;

use App\Module\Party\Contract\PartyLinkSource;
use App\Module\Property\Domain\PropertyRepository;

/**
 * Meldet den Stammdaten, wer Eigentum haelt.
 *
 * Die erste echte Umsetzung von PartyLinkSource. Bis hierher gab es die
 * Loeschsperre der Stammdaten, aber nichts, was sie ausgeloest haette — nur
 * eine Attrappe im Testlauf.
 *
 * Die Richtung ist Absicht: Party darf dieses Modul nicht kennen, dieses
 * Modul aber Party.
 */
final readonly class UnitOwnerLinks implements PartyLinkSource
{
    public function __construct(private PropertyRepository $properties)
    {
    }

    public function linksTo(array $partyIds): array
    {
        $links = [];

        foreach ($this->properties->owningParties($partyIds) as $partyId) {
            $links[$partyId] = ['property.owner.link'];
        }

        return $links;
    }
}
