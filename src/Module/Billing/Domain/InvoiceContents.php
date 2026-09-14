<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Billing\Domain;

use App\Shared\Money\Money;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Das Schreiben, wie es hinausging — eingefroren.
 *
 * Alles, was § 14 Abs. 4 UStG verlangt, steht hier als Text und Zahl und
 * nicht als Verweis: wer umzieht, seinen Namen aendert oder seine
 * Steuernummer, aendert damit keine zugestellte Rechnung. Dieselbe Haltung
 * wie bei {@see StatementDocument} und {@see PlanContents}.
 *
 * **Und es ist die Stelle, an der spaeter eine XRechnung andockt.** Wer XML
 * erzeugt, liest diese eine Gestalt und nicht die halbe Anwendung zusammen.
 *
 * Die vier Mietpositionen stehen einzeln, weil der Mieter sie einzeln
 * wiederfinden muss — auf seinem Kontoauszug steht eine Summe, im Vertrag
 * stehen die Teile.
 */
#[ORM\Embeddable]
final class InvoiceContents
{
    #[ORM\Column(name: 'landlord_name', type: Types::STRING, length: 400)]
    private string $landlordName;

    #[ORM\Column(name: 'landlord_address', type: Types::STRING, length: 400)]
    private string $landlordAddress;

    #[ORM\Column(name: 'landlord_tax_number', type: Types::STRING, length: 40)]
    private string $landlordTaxNumber;

    #[ORM\Column(name: 'tenant_name', type: Types::STRING, length: 400)]
    private string $tenantName;

    #[ORM\Column(name: 'tenant_address', type: Types::STRING, length: 400)]
    private string $tenantAddress;

    /** „20001 · Rosenweg 12–14, Einheit 3 · Ladenlokal EG" — der Gegenstand der Leistung. */
    #[ORM\Column(name: 'let_label', type: Types::STRING, length: 400)]
    private string $letLabel;

    #[ORM\Column(name: 'rent_base', type: Types::BIGINT)]
    private int $base;

    #[ORM\Column(name: 'rent_operating', type: Types::BIGINT)]
    private int $operatingCosts;

    #[ORM\Column(name: 'rent_heating', type: Types::BIGINT)]
    private int $heating;

    #[ORM\Column(name: 'rent_parking', type: Types::BIGINT)]
    private int $parking;

    #[ORM\Embedded(class: Taxation::class, columnPrefix: false)]
    private Taxation $taxation;

    #[ORM\Column(name: 'payee_name', type: Types::STRING, length: 200)]
    private string $payeeName;

    #[ORM\Column(name: 'payee_iban', type: Types::STRING, length: 34)]
    private string $payeeIban;

    #[ORM\Embedded(class: EInvoiceData::class, columnPrefix: false)]
    private EInvoiceData $eInvoice;

    private function __construct()
    {
        $this->landlordName = '';
        $this->landlordAddress = '';
        $this->landlordTaxNumber = '';
        $this->tenantName = '';
        $this->tenantAddress = '';
        $this->letLabel = '';
        $this->base = 0;
        $this->operatingCosts = 0;
        $this->heating = 0;
        $this->parking = 0;
        $this->taxation = Taxation::exempt();
        $this->payeeName = '';
        $this->payeeIban = '';
        $this->eInvoice = EInvoiceData::none();
    }

    /** Ein Entwurf hat noch nichts eingefroren. */
    public static function nothing(): self
    {
        return new self();
    }

    /**
     * Was die Freigabe festhaelt.
     *
     * Ein Konstruktor mit dreizehn Stellungsparametern waere eine Zeile, in
     * der niemand mehr sieht, welcher Name an welcher Stelle steht. Der
     * Vorschlag traegt sie schon benannt — von dort werden sie uebernommen.
     */
    public static function of(ProposedInvoice $proposal): self
    {
        $contents = new self();
        $contents->landlordName = $proposal->landlordName;
        $contents->landlordAddress = $proposal->landlordAddress;
        $contents->landlordTaxNumber = $proposal->landlordTaxNumber;
        $contents->tenantName = $proposal->tenantName;
        $contents->tenantAddress = $proposal->tenantAddress;
        $contents->letLabel = $proposal->letLabel;
        $contents->base = $proposal->base->cents();
        $contents->operatingCosts = $proposal->operatingCosts->cents();
        $contents->heating = $proposal->heating->cents();
        $contents->parking = $proposal->parking->cents();
        $contents->taxation = $proposal->taxation;
        $contents->payeeName = $proposal->payeeName;
        $contents->payeeIban = $proposal->payeeIban;
        $contents->eInvoice = $proposal->eInvoice;

        return $contents;
    }

    /** Dieselbe Gestalt zurueck — der Entwurf rechnet sie, das Schreiben liest sie. */
    public function asProposed(Validity $validity): ProposedInvoice
    {
        return new ProposedInvoice(
            landlordName: $this->landlordName,
            landlordAddress: $this->landlordAddress,
            landlordTaxNumber: $this->landlordTaxNumber,
            tenantName: $this->tenantName,
            tenantAddress: $this->tenantAddress,
            letLabel: $this->letLabel,
            base: Money::fromCents($this->base),
            operatingCosts: Money::fromCents($this->operatingCosts),
            heating: Money::fromCents($this->heating),
            parking: Money::fromCents($this->parking),
            taxation: $this->taxation,
            payeeName: $this->payeeName,
            payeeIban: $this->payeeIban,
            validity: $validity,
            eInvoice: $this->eInvoice,
        );
    }
}
