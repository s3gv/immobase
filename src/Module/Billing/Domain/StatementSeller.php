<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Billing\Domain;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Wer die Abrechnung stellt und wohin gezahlt wird — eingefroren.
 *
 * Nur bei einem Mietverhaeltnis mit Umsatzsteuer gefuellt: dann ist die
 * Abrechnung eine Rechnung, und eine Rechnung nennt den leistenden
 * Unternehmer mit Anschrift und Steuernummer (§ 14 Abs. 4 Nr. 1 und 2 UStG).
 * Das ist der Eigentuemer am letzten Tag des abgerechneten Zeitraums — nicht
 * die Verwaltung, die das Schreiben verschickt.
 */
#[ORM\Embeddable]
final class StatementSeller
{
    #[ORM\Column(name: 'landlord_name', type: Types::STRING, length: 400)]
    private string $name = '';

    #[ORM\Column(name: 'landlord_address', type: Types::TEXT)]
    private string $address = '';

    #[ORM\Column(name: 'landlord_tax_number', type: Types::STRING, length: 40)]
    private string $taxNumber = '';

    #[ORM\Column(name: 'payee_name', type: Types::STRING, length: 200)]
    private string $payeeName = '';

    #[ORM\Column(name: 'payee_iban', type: Types::STRING, length: 34)]
    private string $payeeIban = '';

    private function __construct()
    {
    }

    public static function none(): self
    {
        return new self();
    }

    public static function of(string $name, string $address, string $taxNumber, string $payeeName, string $payeeIban): self
    {
        $seller = new self();
        [$seller->name, $seller->address, $seller->taxNumber] = [$name, $address, $taxNumber];
        [$seller->payeeName, $seller->payeeIban] = [$payeeName, $payeeIban];

        return $seller;
    }

    public function name(): string
    {
        return $this->name;
    }

    /** Mehrzeilig, wie sie ins Anschriftfeld gehoert. */
    public function address(): string
    {
        return $this->address;
    }

    public function taxNumber(): string
    {
        return $this->taxNumber;
    }

    public function payeeName(): string
    {
        return $this->payeeName;
    }

    public function payeeIban(): string
    {
        return $this->payeeIban;
    }
}
