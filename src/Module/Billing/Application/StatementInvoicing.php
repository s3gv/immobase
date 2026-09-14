<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Billing\Application;

use App\Module\Billing\Domain\Letting;
use App\Module\Billing\Domain\Proposal;
use App\Module\Billing\Domain\ProposedDocument;
use App\Module\Billing\Domain\Statement;
use App\Module\Billing\Domain\StatementSeller;
use App\Module\Property\Contract\PropertyDirectory;
use App\Module\Property\Contract\UnitOwnership;
use App\Module\Tenancy\Contract\TenancyDirectory;

/**
 * Was eine Abrechnung mit Umsatzsteuer zur Rechnung macht.
 *
 * Wer sie stellt — der Eigentuemer am letzten Tag des Zeitraums, mit
 * Anschrift und Steuernummer —, wohin gezahlt wird und was die E-Rechnung
 * braucht. Nur fuer Schreiben an Mietverhaeltnisse mit Umsatzsteuer; alle
 * anderen bleiben, wie sie sind.
 *
 * Die Freigabe friert es ein, die Vorschau zeigt vorher, was fehlt. Beide
 * fragen hier: was die Vorschau durchlaesst, muss die Freigabe auch
 * durchlassen.
 */
final readonly class StatementInvoicing
{
    public function __construct(
        private UnitOwnership $ownership,
        private Addressed $addressed,
        private PropertyDirectory $properties,
        private TenancyDirectory $tenancies,
        private CaptureEInvoiceData $capture,
    ) {
    }

    public function of(Statement $statement, ProposedDocument $proposed): Letting
    {
        $letting = $proposed->letting;

        if (!$letting->isTaxed()) {
            return $letting;
        }

        $owners = $this->ownership->ownersOn($proposed->unitId, $proposed->to);
        $landlord = $this->addressed->asLandlord($owners);
        $property = $this->properties->byIds([$statement->propertyId()])[$statement->propertyId()] ?? null;
        $tenancy = null === $letting->tenancyId() ? null : $this->tenancies->brief($letting->tenancyId());

        return $letting->invoicedBy(
            StatementSeller::of(
                $landlord['label'] ?? '',
                $landlord['address'] ?? '',
                $landlord['taxNumber'] ?? '',
                $property->payeeName ?? '',
                $property->payeeIban ?? '',
            ),
            $this->capture->of($owners, $tenancy->tenantPartyIds ?? [], $tenancy, $property, false),
        );
    }

    /**
     * Was jedem Schreiben mit Umsatzsteuer fehlt — nur die, denen etwas fehlt.
     *
     * @return list<array{label: string, missing: list<string>}>
     */
    public function gapsIn(Statement $statement, Proposal $proposal): array
    {
        $found = [];

        foreach ($proposal->documents as $proposed) {
            $missing = self::gapsOf($this->of($statement, $proposed));

            if ([] !== $missing) {
                $found[] = ['label' => $proposed->unitLabel.' · '.$proposed->recipientLabel, 'missing' => $missing];
            }
        }

        return $found;
    }

    /**
     * @return list<string> Uebersetzungsschluessel
     */
    public static function gapsOf(Letting $letting): array
    {
        if (!$letting->isTaxed()) {
            return [];
        }

        $seller = $letting->seller();
        $missing = array_keys(array_filter([
            'billing.invoice.missing.landlord' => '' === $seller->name(),
            'billing.invoice.missing.tax_number' => '' === $seller->taxNumber(),
            'billing.invoice.missing.payee' => '' === $seller->payeeIban() && !$letting->eInvoice()->isDirectDebit(),
        ]));

        return [...$missing, ...EInvoiceGaps::of($letting->eInvoice())];
    }
}
