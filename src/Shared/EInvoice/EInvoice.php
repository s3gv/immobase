<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Shared\EInvoice;

use App\Shared\Money\Money;
use DateTimeImmutable;

/**
 * Eine E-Rechnung, bevor sie XML ist.
 *
 * Neutral gegenueber den Modulen: die Dauermietrechnung und die Abrechnung
 * uebersetzen ihren eingefrorenen Beleg hierher, und {@see CiiWriter} kennt
 * nur diese Gestalt. So gibt es eine einzige Stelle, die weiss, wie XRechnung
 * aussieht — und zwei, die wissen, was auf ihrem Beleg steht.
 *
 * **Ein Steuersatz, Kategorie S.** Steuerfreie Belege bekommen keine
 * E-Rechnung; Wohnraum und Hausgeld sind dauerhaft ausgenommen. Die Summen
 * werden gerechnet und nicht mitgegeben: eine gespeicherte Summe neben ihren
 * Teilen waere die zweite Wahrheit (BR-CO-10 bis BR-CO-16).
 */
final readonly class EInvoice
{
    /** Handelsrechnung (UNTDID 1001). */
    public const string INVOICE = '380';

    /** Korrigierte Rechnung (UNTDID 1001) — mit Verweis auf die vorige. */
    public const string CORRECTED = '384';

    /**
     * @param list<string>       $notes
     * @param list<EInvoiceLine> $lines
     */
    public function __construct(
        public string $number,
        public DateTimeImmutable $issuedOn,
        public EInvoiceParty $seller,
        public EInvoiceParty $buyer,
        /** Wer bei der Verwaltung Rueckfragen beantwortet — BG-6. */
        public string $contactName,
        public string $contactPhone,
        public string $contactEmail,
        public string $buyerReference,
        public string $contractReference,
        public DateTimeImmutable $periodFrom,
        public ?DateTimeImmutable $periodTo,
        public array $lines,
        public int $rateBps,
        public EInvoicePayment $payment,
        public array $notes = [],
        /** Die berichtigte Rechnung — dann ist diese eine korrigierte. */
        public ?string $precedingNumber = null,
        /** Bereits gezahlt, brutto — bei einer Endrechnung die Vorauszahlungen (BT-113). */
        public ?Money $prepaid = null,
        /** Nur, wenn der Zahlungsempfaenger ein anderer ist als der Verkaeufer (BT-59). */
        public string $payeeName = '',
    ) {
    }

    public function typeCode(): string
    {
        return null === $this->precedingNumber ? self::INVOICE : self::CORRECTED;
    }

    public function lineTotal(): Money
    {
        $total = Money::zero();

        foreach ($this->lines as $line) {
            $total = $total->plus($line->net);
        }

        return $total;
    }

    /** Einmal auf die Summe, kaufmaennisch gerundet (BR-CO-17). */
    public function tax(): Money
    {
        return $this->lineTotal()->basisPoints($this->rateBps);
    }

    public function grandTotal(): Money
    {
        return $this->lineTotal()->plus($this->tax());
    }

    public function duePayable(): Money
    {
        return $this->grandTotal()->minus($this->prepaid ?? Money::zero());
    }
}
