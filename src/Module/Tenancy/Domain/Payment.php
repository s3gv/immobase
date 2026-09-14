<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Tenancy\Domain;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Wie und wann gezahlt wird — und wie die Rechnung ankommt.
 *
 * Zwei Angaben, die immer zusammen gepflegt werden und nur zusammen einen
 * Sinn ergeben: eine Lastschrift zum dritten Werktag ist eine Abrede, „per
 * Lastschrift" allein ist keine.
 *
 * Dazu, was eine E-Rechnung ueber die Zahlung wissen muss: das Mandat und die
 * IBAN einer Lastschrift, die Referenz und die Adresse des Mieters. Siehe
 * {@see EInvoiceTerms}.
 */
#[ORM\Embeddable]
final class Payment
{
    #[ORM\Column(name: 'payment_method', type: Types::STRING, length: 16, enumType: PaymentMethod::class)]
    private PaymentMethod $method;

    #[ORM\Column(name: 'payment_due', type: Types::STRING, length: 24, enumType: PaymentDue::class)]
    private PaymentDue $due;

    #[ORM\Embedded(class: EInvoiceTerms::class, columnPrefix: false)]
    private EInvoiceTerms $eInvoice;

    private function __construct(PaymentMethod $method, PaymentDue $due, EInvoiceTerms $eInvoice)
    {
        $this->method = $method;
        $this->due = $due;
        $this->eInvoice = $eInvoice;
    }

    /** Ueberweisung zum dritten Werktag — was in den meisten Vertraegen steht. */
    public static function usual(): self
    {
        return new self(PaymentMethod::Transfer, PaymentDue::ThirdWorkingDay, EInvoiceTerms::none());
    }

    public static function of(PaymentMethod $method, PaymentDue $due, ?EInvoiceTerms $eInvoice = null): self
    {
        return new self($method, $due, $eInvoice ?? EInvoiceTerms::none());
    }

    public function eInvoice(): EInvoiceTerms
    {
        return $this->eInvoice;
    }

    public function method(): PaymentMethod
    {
        return $this->method;
    }

    public function due(): PaymentDue
    {
        return $this->due;
    }
}
