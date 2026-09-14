<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Billing\Application;

use App\Module\Billing\Domain\ProposedInvoice;
use App\Module\Billing\Domain\RentInvoice;
use App\Module\Billing\Domain\Taxation;
use App\Module\Property\Contract\PropertyDirectory;
use App\Module\Property\Contract\UnitOwnership;
use App\Module\Tenancy\Contract\RentPeriod;
use App\Module\Tenancy\Contract\TenancyBrief;
use App\Module\Tenancy\Contract\TenancyDirectory;
use App\Shared\Money\Money;

/**
 * Die Dauermietrechnung zusammenstellen.
 *
 * Ein Entwurf wird gerechnet, ein ausgestellter gelesen — und beide Male
 * kommt dieselbe Gestalt heraus. Die Vorschau zeigt damit, was hinausgehen
 * wird, und das zugestellte Schreiben zeigt, was hinausging.
 *
 * Der Schnitt liegt genau an der Ausstellung, und das ist der ganze Trick an
 * der Unumkehrbarkeit: danach wird nichts mehr gerechnet, also kann die
 * naechste Mieterhoehung auch nichts mehr aendern.
 */
final readonly class ComposeRentInvoice
{
    public function __construct(
        private TenancyDirectory $tenancies,
        private UnitOwnership $ownership,
        private PropertyDirectory $properties,
        private Addressed $addressed,
        private CaptureEInvoiceData $capture,
    ) {
    }

    public function of(RentInvoice $invoice): ProposedInvoice
    {
        if (!$invoice->release()->isDraft()) {
            return $invoice->contents()->asProposed($invoice->validity());
        }

        return $this->computed($invoice);
    }

    /** Das Mietverhaeltnis dahinter — null, wenn es geloescht oder ein Entwurf ist. */
    public function tenancyOf(RentInvoice $invoice): ?TenancyBrief
    {
        return $this->tenancies->brief($invoice->tenancyId());
    }

    /** Die Stufe, die an diesem Tag gilt — fuer den Hinweis, woher die Zahlen kommen. */
    public function stepOf(RentInvoice $invoice): ?RentPeriod
    {
        return $this->tenancyOf($invoice)?->stepOn($invoice->validity()->from());
    }

    private function computed(RentInvoice $invoice): ProposedInvoice
    {
        $from = $invoice->validity()->from();
        $tenancy = $this->tenancyOf($invoice);
        $step = $tenancy?->stepOn($from);
        // Der Vermieter ist der Eigentuemer am Tag, ab dem die Rechnung gilt:
        // wer die Einheit inzwischen verkauft hat, hat sie damals vermietet.
        $owners = null === $tenancy ? [] : $this->ownership->ownersOn($tenancy->unitId, $from);
        $landlord = $this->addressed->asLandlord($owners);
        $tenant = $this->addressed->of($tenancy->tenantPartyIds ?? []);
        $property = null === $tenancy ? null : ($this->properties->byIds([$tenancy->propertyId])[$tenancy->propertyId] ?? null);

        return new ProposedInvoice(
            landlordName: $landlord['label'] ?? '',
            landlordAddress: $landlord['address'] ?? '',
            landlordTaxNumber: $landlord['taxNumber'] ?? '',
            tenantName: $tenant['label'] ?? '',
            tenantAddress: $tenant['address'] ?? '',
            letLabel: self::letLabel($tenancy),
            base: $step->base ?? Money::zero(),
            operatingCosts: $step->operatingCosts ?? Money::zero(),
            heating: $step->heating ?? Money::zero(),
            parking: $step->parking ?? Money::zero(),
            taxation: self::taxationOf($tenancy),
            payeeName: $property->payeeName ?? '',
            payeeIban: $property->payeeIban ?? '',
            validity: $invoice->validity(),
            eInvoice: $this->capture->of($owners, $tenancy->tenantPartyIds ?? [], $tenancy, $property, true),
        );
    }

    /** Kein Mietverhaeltnis heisst: keine Umsatzsteuer, nicht „vielleicht doch". */
    private static function taxationOf(?TenancyBrief $tenancy): Taxation
    {
        if (null === $tenancy || !$tenancy->vatCharged) {
            return Taxation::exempt();
        }

        return Taxation::at($tenancy->vatRateBps);
    }

    /**
     * Art und Umfang der Leistung — § 14 Abs. 4 Nr. 5 UStG.
     *
     * „20001 · Rosenweg 12–14 · Ladenlokal EG, Rosenweg 12, 40213
     * Duesseldorf": Objekt, Einheit und Anschrift. Die Kurzform der Einheit
     * traegt das Objekt schon, darum steht es nicht noch einmal davor — die
     * Anschrift dagegen fehlt ihr, und ohne sie ist der Mietgegenstand nicht
     * auffindbar.
     */
    private static function letLabel(?TenancyBrief $tenancy): string
    {
        if (null === $tenancy) {
            return '';
        }

        return '' === $tenancy->address
            ? $tenancy->unitLabel
            : $tenancy->unitLabel.', '.$tenancy->address;
    }
}
