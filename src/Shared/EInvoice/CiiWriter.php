<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Shared\EInvoice;

use App\Shared\Money\Money;
use DateTimeImmutable;
use DOMDocument;
use DOMElement;

/**
 * Eine E-Rechnung als XRechnung 3.0 in der Syntax UN/CEFACT CII.
 *
 * **Selbst geschrieben und nicht aus einer Bibliothek.** Die gaengigen
 * PHP-Bibliotheken ziehen einen PDF-Parser unter LGPL oder mPDF unter GPL
 * nach — fuer ein XML mit einer Handvoll Positionen. Was hier steht, ist die
 * Elementfolge des CII-Schemas D16B, nicht mehr.
 *
 * **Bytegenau reproduzierbar.** Feste Reihenfolge, feste Einrueckung, keine
 * Zeitstempel ausser den Belegdaten: dieselbe Rechnung ergibt heute und in
 * acht Jahren dieselbe Datei. Die Tests vergleichen gegen Referenzdateien.
 *
 * CII statt UBL, weil ZUGFeRD genau dieses XML in ein PDF einbettet.
 */
final class CiiWriter
{
    /** Die Spezifikation, nach der geschrieben wird — BT-24. */
    public const string XRECHNUNG = 'urn:cen.eu:en16931:2017#compliant#urn:xeinkauf.de:kosit:xrechnung_3.0';
    private const string RSM = 'urn:un:unece:uncefact:data:standard:CrossIndustryInvoice:100';
    private const string RAM = 'urn:un:unece:uncefact:data:standard:ReusableAggregateBusinessInformationEntity:100';
    private const string UDT = 'urn:un:unece:uncefact:data:standard:UnqualifiedDataType:100';

    /** Der Geschaeftsprozess — BT-23, seit XRechnung 3.0 Pflicht. */
    private const string PROCESS = 'urn:fdc:peppol.eu:2017:poacc:billing:01:1.0';

    private DOMDocument $document;

    public function write(EInvoice $invoice): string
    {
        $this->document = new DOMDocument('1.0', 'UTF-8');
        $this->document->formatOutput = true;

        $root = $this->document->createElementNS(self::RSM, 'rsm:CrossIndustryInvoice');
        $root->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:ram', self::RAM);
        $root->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:udt', self::UDT);
        $this->document->appendChild($root);

        $this->context($root);
        $this->header($root, $invoice);
        $transaction = $this->rsm($root, 'SupplyChainTradeTransaction');

        foreach ($invoice->lines as $at => $line) {
            $this->line($transaction, $invoice, $at + 1, $line);
        }

        $this->agreement($transaction, $invoice);
        $this->ram($transaction, 'ApplicableHeaderTradeDelivery');
        (new CiiSettlement($this))->write($this->ram($transaction, 'ApplicableHeaderTradeSettlement'), $invoice);

        return (string) $this->document->saveXML();
    }

    /** Ein Element im Namensraum `ram`, mit Text, wenn es einen gibt. */
    public function ram(DOMElement $parent, string $name, ?string $text = null): DOMElement
    {
        $element = $this->document->createElementNS(self::RAM, 'ram:'.$name);

        if (null !== $text) {
            $element->appendChild($this->document->createTextNode($text));
        }

        $parent->appendChild($element);

        return $element;
    }

    /** Ein Datum im Format 102 — `JJJJMMTT`. */
    public function date(DOMElement $parent, string $name, DateTimeImmutable $day): void
    {
        $wrapper = $this->ram($parent, $name);
        $string = $this->document->createElementNS(self::UDT, 'udt:DateTimeString', $day->format('Ymd'));
        $string->setAttribute('format', '102');
        $wrapper->appendChild($string);
    }

    /** Ein Betrag mit zwei Nachkommastellen, auf Wunsch mit Waehrung. */
    public function amount(DOMElement $parent, string $name, Money $money, bool $withCurrency = false): void
    {
        $element = $this->ram($parent, $name, $money->toDecimal());

        if ($withCurrency) {
            $element->setAttribute('currencyID', 'EUR');
        }
    }

    /** 1900 wird zu „19", 750 zu „7.5". */
    public static function percent(int $basisPoints): string
    {
        $whole = intdiv($basisPoints, 100);
        $fraction = rtrim(\sprintf('%02d', $basisPoints % 100), '0');

        return '' === $fraction ? (string) $whole : $whole.'.'.$fraction;
    }

    /** Die Steuer einer Position oder des Belegs: Umsatzsteuer, Kategorie S. */
    public function taxOf(DOMElement $parent, EInvoice $invoice, bool $withAmounts): void
    {
        $tax = $this->ram($parent, 'ApplicableTradeTax');

        if ($withAmounts) {
            $this->amount($tax, 'CalculatedAmount', $invoice->tax());
        }

        $this->ram($tax, 'TypeCode', 'VAT');

        if ($withAmounts) {
            $this->amount($tax, 'BasisAmount', $invoice->lineTotal());
        }

        $this->ram($tax, 'CategoryCode', 'S');
        $this->ram($tax, 'RateApplicablePercent', self::percent($invoice->rateBps));
    }

    /** Der Leistungszeitraum — ein offenes Ende bleibt offen (BR-CO-19). */
    public function period(DOMElement $parent, EInvoice $invoice): void
    {
        $period = $this->ram($parent, 'BillingSpecifiedPeriod');
        $this->date($period, 'StartDateTime', $invoice->periodFrom);

        if (null !== $invoice->periodTo) {
            $this->date($period, 'EndDateTime', $invoice->periodTo);
        }
    }

    private function rsm(DOMElement $parent, string $name): DOMElement
    {
        $element = $this->document->createElementNS(self::RSM, 'rsm:'.$name);
        $parent->appendChild($element);

        return $element;
    }

    private function context(DOMElement $root): void
    {
        $context = $this->rsm($root, 'ExchangedDocumentContext');
        $this->ram($this->ram($context, 'BusinessProcessSpecifiedDocumentContextParameter'), 'ID', self::PROCESS);
        $this->ram($this->ram($context, 'GuidelineSpecifiedDocumentContextParameter'), 'ID', self::XRECHNUNG);
    }

    private function header(DOMElement $root, EInvoice $invoice): void
    {
        $header = $this->rsm($root, 'ExchangedDocument');
        $this->ram($header, 'ID', $invoice->number);
        $this->ram($header, 'TypeCode', $invoice->typeCode());
        $this->date($header, 'IssueDateTime', $invoice->issuedOn);

        foreach ($invoice->notes as $note) {
            $this->ram($this->ram($header, 'IncludedNote'), 'Content', $note);
        }
    }

    private function line(DOMElement $transaction, EInvoice $invoice, int $number, EInvoiceLine $line): void
    {
        $item = $this->ram($transaction, 'IncludedSupplyChainTradeLineItem');
        $this->ram($this->ram($item, 'AssociatedDocumentLineDocument'), 'LineID', (string) $number);
        $this->ram($this->ram($item, 'SpecifiedTradeProduct'), 'Name', $line->name);
        $this->amount($this->ram($this->ram($item, 'SpecifiedLineTradeAgreement'), 'NetPriceProductTradePrice'), 'ChargeAmount', $line->net);
        $this->ram($this->ram($item, 'SpecifiedLineTradeDelivery'), 'BilledQuantity', '1')->setAttribute('unitCode', $line->unitCode);

        $settlement = $this->ram($item, 'SpecifiedLineTradeSettlement');
        $this->taxOf($settlement, $invoice, false);
        $this->amount($this->ram($settlement, 'SpecifiedTradeSettlementLineMonetarySummation'), 'LineTotalAmount', $line->net);
    }

    private function agreement(DOMElement $transaction, EInvoice $invoice): void
    {
        $agreement = $this->ram($transaction, 'ApplicableHeaderTradeAgreement');
        $this->ram($agreement, 'BuyerReference', $invoice->buyerReference);
        $this->party($this->ram($agreement, 'SellerTradeParty'), $invoice->seller, $invoice);
        $this->party($this->ram($agreement, 'BuyerTradeParty'), $invoice->buyer, null);
        $this->ram($this->ram($agreement, 'ContractReferencedDocument'), 'IssuerAssignedID', $invoice->contractReference);
    }

    /** Verkaeufer mit Ansprechpartner und Steuernummer, Kaeufer ohne. */
    private function party(DOMElement $element, EInvoiceParty $party, ?EInvoice $asSeller): void
    {
        $this->ram($element, 'Name', $party->name);

        if (null !== $asSeller) {
            $contact = $this->ram($element, 'DefinedTradeContact');
            $this->ram($contact, 'PersonName', $asSeller->contactName);
            $this->ram($this->ram($contact, 'TelephoneUniversalCommunication'), 'CompleteNumber', $asSeller->contactPhone);
            $this->ram($this->ram($contact, 'EmailURIUniversalCommunication'), 'URIID', $asSeller->contactEmail);
        }

        $address = $this->ram($element, 'PostalTradeAddress');
        $this->ram($address, 'PostcodeCode', $party->postalCode);
        $this->ram($address, 'LineOne', $party->street);
        $this->ram($address, 'CityName', $party->city);
        $this->ram($address, 'CountryID', 'DE');
        $this->ram($this->ram($element, 'URIUniversalCommunication'), 'URIID', $party->eAddress)->setAttribute('schemeID', 'EM');

        if (null !== $asSeller) {
            $this->ram($this->ram($element, 'SpecifiedTaxRegistration'), 'ID', $party->normalisedTaxNumber())->setAttribute('schemeID', $party->taxScheme());
        }
    }
}
