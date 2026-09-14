<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Tests\Module\Billing\Application;

use App\Module\Billing\Application\EInvoiceTerms;
use App\Module\Billing\Domain\EInvoiceData;
use App\Shared\EInvoice\EInvoicePayment;
use App\Shared\Money\Money;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Translation\IdentityTranslator;

/**
 * Wie eine Abrechnung bezahlt wird — vom Saldo aus gedacht.
 *
 * Eine Nachzahlung wird ueberwiesen oder eingezogen. Ein Guthaben wird nie
 * eingezogen: es geht an den Mieter zurueck, und die E-Rechnung darf nicht
 * „Lastschrift" draufschreiben, wo Geld in die andere Richtung fliesst.
 */
final class EInvoiceTermsTest extends TestCase
{
    public function testADueStatementByDirectDebitIsCollected(): void
    {
        $payment = self::terms()->forStatement(self::byDirectDebit(), 'DE02120300000000202051', 'WEG', Money::fromCents(12493), 'Mieter GmbH');

        self::assertSame(EInvoicePayment::DIRECT_DEBIT, $payment->code);
        self::assertSame('billing.result.payable billing.einvoice.terms.direct_debit', $payment->terms);
    }

    public function testADueStatementByTransferIsPaidToTheLandlord(): void
    {
        $payment = self::terms()->forStatement(self::byTransfer(), 'DE02120300000000202051', 'WEG', Money::fromCents(12493), 'Mieter GmbH');

        self::assertSame(EInvoicePayment::CREDIT_TRANSFER, $payment->code);
        self::assertSame('DE02120300000000202051', $payment->payeeIban);
    }

    /** Ein Guthaben bei Lastschrift geht zurueck auf das Konto, von dem eingezogen wurde. */
    public function testACreditByDirectDebitIsRefundedAndNeverCollected(): void
    {
        $payment = self::terms()->forStatement(self::byDirectDebit(), 'DE02120300000000202051', 'WEG', Money::fromCents(-109040), 'Mieter GmbH');

        self::assertSame(EInvoicePayment::CREDIT_TRANSFER, $payment->code, 'Keine Lastschrift');
        self::assertSame('DE89370400440532013000', $payment->payeeIban, 'Das Konto des Mieters');
        self::assertSame('Mieter GmbH', $payment->payeeName);
        self::assertSame('', $payment->mandate);
    }

    /** Ohne bekanntes Konto des Mieters gibt die Rechnung keinen Weg vor — schon gar nicht das Konto des Vermieters. */
    public function testACreditByTransferNamesNoAccount(): void
    {
        $payment = self::terms()->forStatement(self::byTransfer(), 'DE02120300000000202051', 'WEG', Money::fromCents(-109040), 'Mieter GmbH');

        self::assertSame(EInvoicePayment::NOT_DEFINED, $payment->code);
        self::assertSame('', $payment->payeeIban);
        self::assertSame('billing.einvoice.terms.credit', $payment->terms);
    }

    public function testNothingDueNamesNoWay(): void
    {
        $payment = self::terms()->forStatement(self::byDirectDebit(), 'DE02120300000000202051', 'WEG', Money::zero(), 'Mieter GmbH');

        self::assertSame(EInvoicePayment::NOT_DEFINED, $payment->code);
    }

    private static function terms(): EInvoiceTerms
    {
        return new EInvoiceTerms(new IdentityTranslator());
    }

    private static function byDirectDebit(): EInvoiceData
    {
        return self::data(['method' => 'direct_debit', 'due' => '', 'mandate' => 'M-1', 'debtorIban' => 'DE89370400440532013000', 'creditorId' => 'DE98ZZZ09999999999']);
    }

    private static function byTransfer(): EInvoiceData
    {
        return self::data(['method' => 'transfer', 'due' => '', 'mandate' => '', 'debtorIban' => '', 'creditorId' => '']);
    }

    /**
     * @param array{method: string, due: string, mandate: string, debtorIban: string, creditorId: string} $payment
     */
    private static function data(array $payment): EInvoiceData
    {
        return EInvoiceData::of(
            ['street' => 'Weg 1', 'postalCode' => '12345', 'city' => 'Ort'],
            ['street' => 'Weg 2', 'postalCode' => '12345', 'city' => 'Ort'],
            ['reference' => 'REF', 'eAddress' => 'a@example.org'],
            ['name' => 'Verwaltung', 'phone' => '0211 1', 'email' => 'v@example.org'],
            $payment,
        );
    }
}
