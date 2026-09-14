<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Billing\Domain;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * An welches Mietverhaeltnis ein Schreiben geht — und ob mit Umsatzsteuer.
 *
 * Eingefroren am Tag der Freigabe: wer spaeter auf die Option verzichtet,
 * aendert damit keine zugestellte Abrechnung. Eine Hausgeldabrechnung geht an
 * Eigentuemer und hat keines; sie ist steuerfrei (§ 4 Nr. 13 UStG).
 *
 * Mit Umsatzsteuer ist das Schreiben eine Rechnung, und dann gehoert dazu,
 * wer sie stellt ({@see StatementSeller}) und was ihre E-Rechnung braucht
 * ({@see EInvoiceData}). Ohne Umsatzsteuer bleibt beides leer.
 */
#[ORM\Embeddable]
final class Letting
{
    #[ORM\Column(name: 'tenancy_id', type: Types::GUID, nullable: true)]
    private ?string $tenancyId;

    #[ORM\Column(name: 'tenancy_number', type: Types::INTEGER, nullable: true)]
    private ?int $tenancyNumber;

    #[ORM\Embedded(class: Taxation::class, columnPrefix: false)]
    private Taxation $taxation;

    #[ORM\Embedded(class: StatementSeller::class, columnPrefix: false)]
    private StatementSeller $seller;

    #[ORM\Embedded(class: EInvoiceData::class, columnPrefix: false)]
    private EInvoiceData $eInvoice;

    public function __construct(?string $tenancyId, ?int $tenancyNumber, Taxation $taxation)
    {
        $this->tenancyId = $tenancyId;
        $this->tenancyNumber = $tenancyNumber;
        $this->taxation = $taxation;
        $this->seller = StatementSeller::none();
        $this->eInvoice = EInvoiceData::none();
    }

    /** Dasselbe Mietverhaeltnis — mit dem, was eine Rechnung darueber braucht. */
    public function invoicedBy(StatementSeller $seller, EInvoiceData $eInvoice): self
    {
        $invoiced = new self($this->tenancyId, $this->tenancyNumber, $this->taxation);
        $invoiced->seller = $seller;
        $invoiced->eInvoice = $eInvoice;

        return $invoiced;
    }

    public function seller(): StatementSeller
    {
        return $this->seller;
    }

    public function eInvoice(): EInvoiceData
    {
        return $this->eInvoice;
    }

    /** Kein Mietverhaeltnis — also auch keine Umsatzsteuer. */
    public static function none(): self
    {
        return new self(null, null, Taxation::exempt());
    }

    public function tenancyId(): ?string
    {
        return $this->tenancyId;
    }

    public function tenancyNumber(): ?int
    {
        return $this->tenancyNumber;
    }

    public function taxation(): Taxation
    {
        return $this->taxation;
    }

    public function isTaxed(): bool
    {
        return $this->taxation->isCharged();
    }
}
