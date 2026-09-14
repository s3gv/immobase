<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Dunning\Application;

use App\Module\Dunning\Domain\Claim;
use App\Module\Dunning\Domain\Creditor;
use App\Module\Party\Contract\PartyDirectory;
use App\Module\Property\Contract\PropertyDirectory;

/**
 * Wer schreibt an wen.
 *
 * Der Schuldner ist eine Partei — das ist einfach. Der Glaeubiger ist es
 * nicht: bei Hausgeld fordert die **Gemeinschaft**, und die hat keine Partei,
 * sondern ein Objekt; bei Nebenkosten fordert der **Vermieter**, und das ist
 * der Eigentuemer der Einheit am Tag der Faelligkeit.
 *
 * Nicht der heutige Eigentuemer: wer die Wohnung inzwischen verkauft hat, war
 * damals der Glaeubiger, und die Forderung ist mit dem Verkauf nicht
 * mitgegangen. Nachgeschlagen wird das hier nicht mehr — es steht an der
 * Forderung ({@see \App\Module\Dunning\Domain\CreditorIdentity}), und zwar
 * derselbe Wert, nach dem gebuendelt wird. Sonst koennte der Briefkopf einen
 * anderen Glaeubiger nennen als den, fuer den das Schreiben zusammengestellt
 * wurde.
 */
final readonly class Addressed
{
    public function __construct(
        private PartyDirectory $parties,
        private PropertyDirectory $properties,
    ) {
    }

    /** @return array{name: string, address: string} */
    public function debtorOf(Claim $claim): array
    {
        $party = $this->parties->byIds([$claim->debtor()->partyId()])[$claim->debtor()->partyId()] ?? null;

        return [
            'name' => $party->displayName ?? '',
            'address' => implode("\n", $party->postalLines ?? []),
        ];
    }

    /** @return array{name: string, address: string} */
    public function creditorOf(Claim $claim): array
    {
        $creditor = $claim->source()->creditorIdentity();

        return Creditor::Community === $creditor->role
            ? $this->communityOf($creditor->propertyId)
            : $this->joined($creditor->parties());
    }

    /**
     * Die Gemeinschaft — sie heisst nach ihrem Objekt.
     *
     * „WEG Rosenweg 12–14" steht als Name des Objekts da; eine eigene
     * Partei fuer die Gemeinschaft gibt es nicht, und sie waere eine
     * Verdopplung des Objekts mit anderer Anschrift.
     *
     * @return array{name: string, address: string}
     */
    private function communityOf(string $propertyId): array
    {
        $property = $this->properties->byIds([$propertyId])[$propertyId] ?? null;

        return [
            'name' => $property->name ?? '',
            'address' => implode("\n", $property->postalLines ?? []),
        ];
    }

    /**
     * Mehrere Eigentuemer in einer Zeile, die Anschrift des ersten.
     *
     * Ein Ehepaar wohnt an einer Anschrift; eine Erbengemeinschaft nicht
     * unbedingt. Fuer den Regelfall ist das richtig, und ein Brief an drei
     * Anschriften ist dreimal Post und nicht ein Schreiben.
     *
     * @param list<string> $partyIds
     *
     * @return array{name: string, address: string}
     */
    private function joined(array $partyIds): array
    {
        $known = $this->parties->byIds($partyIds);
        $names = [];
        $address = '';

        foreach ($partyIds as $partyId) {
            $party = $known[$partyId] ?? null;

            if (null !== $party) {
                $names[] = $party->displayName;
                $address = '' === $address ? implode("\n", $party->postalLines) : $address;
            }
        }

        return ['name' => implode(' und ', $names), 'address' => $address];
    }
}
