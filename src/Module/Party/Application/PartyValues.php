<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Party\Application;

use App\Module\Party\Domain\Party;
use App\Module\Party\Domain\PartyRole;
use App\Module\Party\Domain\PartyRoles;
use App\Module\Party\Domain\PostalAddress;
use App\Module\Party\Domain\TaxId;

/**
 * Zerlegt einen vorhandenen Stammdatensatz in die Angaben eines Ablaufs.
 *
 * Die Gegenrichtung zu PartyDraft: dort werden aus Eingaben Wertobjekte, hier
 * aus Wertobjekten wieder Eingaben.
 */
final readonly class PartyValues
{
    private function __construct()
    {
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public static function of(Party $party): array
    {
        return [
            'kind' => [
                'kind' => $party->kind()->value,
                'roles' => array_map(static fn (PartyRole $role): string => $role->value, $party->roles()->all()),
            ],
            'name' => [
                'name' => $party->name(),
                'givenName' => $party->givenName() ?? '',
            ],
            'address' => [
                'entries' => array_map(
                    static fn (PostalAddress $address): array => $address->toArray(),
                    $party->addresses()->all(),
                ),
            ],
            'contact' => [
                'emails' => $party->contact()->emails(),
                'phones' => $party->contact()->phones(),
                'taxNumber' => $party->taxId()->toString(),
            ],
            'note' => ['note' => $party->note()],
        ];
    }

    public static function apply(Party $party, PartyDraft $draft): void
    {
        $party->classifyAs($draft->kind());
        $party->rename($draft->name(), $draft->givenName());
        $party->assignRoles(PartyRoles::of($draft->roles()));
        $party->moveTo($draft->addresses());
        $party->reachAt($draft->contact());
        $party->noteThat($draft->note());
        $party->taxedAs(TaxId::of($draft->taxNumber()));
    }
}
