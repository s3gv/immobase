<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Dunning\UserInterface\Controller;

use App\Module\Dunning\Application\TheCreditor;
use App\Module\Dunning\Domain\Creditor;
use App\Module\Dunning\Domain\CreditorIdentity;
use App\Module\Party\Contract\PartyDirectory;
use App\Module\Property\Contract\UnitDirectory;
use App\Module\Property\Contract\UnitOwnership;
use App\Module\Tenancy\Contract\TenancyDirectory;
use DateTimeImmutable;

/**
 * Wer an einer Einheit schuldet — je nachdem, wer fordert.
 *
 * Fordert die Gemeinschaft, schuldet der Eigentuemer; fordert der
 * Eigentuemer, schuldet der Mieter. Das spart zwei Felder und einen ganzen
 * Sonderfall: eine Mahnung an den Falschen ist schlimmer als eine, die nicht
 * hinausgeht.
 *
 * **Beide zum Tag der Faelligkeit**, nicht zu heute. Eine nachgetragene
 * Februarmiete schuldet der, der im Februar dort wohnte, und zwar dem, dem
 * die Wohnung im Februar gehoerte. Wer ausgezogen ist, hat den Rueckstand
 * mitgenommen; wer eingezogen ist, hat ihn nicht geerbt.
 */
final readonly class DebtorOfAUnit
{
    public function __construct(
        private UnitDirectory $units,
        private UnitOwnership $ownership,
        private TenancyDirectory $tenancies,
        private PartyDirectory $parties,
        private TheCreditor $creditor,
    ) {
    }

    /**
     * Beide Seiten auf einmal: wer schuldet und wer fordert.
     *
     * Der Glaeubiger kommt mit, weil er zur selben Antwort gehoert — und weil
     * er beim Vermieter genauso wenig „der Eigentuemer" ist, wie der Schuldner
     * „der Mieter" ist: gemeint sind die Leute, die zum Tag der Faelligkeit an
     * dieser Einheit stehen.
     *
     * @return array{partyId: string, company: bool, creditor: CreditorIdentity, unitId: string}|null
     */
    public function of(string $unitId, Creditor $creditor, DateTimeImmutable $on): ?array
    {
        $unit = '' === $unitId ? null : ($this->units->byIds([$unitId])[$unitId] ?? null);
        $partyId = null === $unit ? null : $this->partyFor($creditor, $unitId, $on);

        if (null === $unit || null === $partyId) {
            return null;
        }

        return [
            'partyId' => $partyId,
            'company' => true === ($this->parties->byIds([$partyId])[$partyId] ?? null)?->isACompany(),
            'creditor' => $this->creditor->of($creditor, $unit->propertyId, $unitId, $on),
            'unitId' => $unitId,
        ];
    }

    private function partyFor(Creditor $creditor, string $unitId, DateTimeImmutable $on): ?string
    {
        if (Creditor::Community === $creditor) {
            return $this->ownership->ownersOn($unitId, $on)[0] ?? null;
        }

        foreach ($this->tenancies->lettable() as $tenancy) {
            if ($tenancy->unitId === $unitId && $tenancy->runsOn($on)) {
                return $tenancy->tenantPartyIds[0] ?? null;
            }
        }

        return null;
    }
}
