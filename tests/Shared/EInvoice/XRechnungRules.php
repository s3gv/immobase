<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Tests\Shared\EInvoice;

use DOMAttr;
use DOMDocument;
use DOMElement;
use DOMNode;
use DOMText;
use DOMXPath;

/**
 * Die Geschaeftsregeln von EN 16931 und XRechnung, die unsere Belege betreffen — in PHP.
 *
 * **Nicht der amtliche Pruefdienst.** Die KoSIT-Regeln liegen als Schematron
 * in XSLT 2.0 vor, und dafuer braucht es Java; das holen wir nicht ins
 * Projekt. Hier stehen die Regeln nachgebildet, die fuer eine
 * Dauermietrechnung und eine Betriebskostenabrechnung ueberhaupt greifen
 * koennen: Pflichtangaben, Summen, Kategorie S, Zahlungsanweisungen und die
 * deutschen Zusatzregeln. Rabatte, Zuschlaege, andere Steuerkategorien und
 * Anhaenge kommen in unseren Belegen nicht vor und sind darum nicht
 * nachgebildet.
 *
 * Die Kennungen sind die der Regelwerke, damit ein Fehler dort nachzuschlagen
 * ist, wo die Regel steht.
 */
final class XRechnungRules
{
    private const string SPECIFICATION = 'urn:cen.eu:en16931:2017#compliant#urn:xeinkauf.de:kosit:xrechnung_3.0';

    private const array TYPE_CODES = ['326', '380', '384', '389', '381', '875', '876', '877'];

    private DOMXPath $xpath;

    /**
     * @return list<string> die verletzten Regeln — leer, wenn keine
     */
    public function violations(string $xml): array
    {
        $document = new DOMDocument();
        $document->loadXML($xml);
        $this->xpath = new DOMXPath($document);
        $this->xpath->registerNamespace('rsm', 'urn:un:unece:uncefact:data:standard:CrossIndustryInvoice:100');
        $this->xpath->registerNamespace('ram', 'urn:un:unece:uncefact:data:standard:ReusableAggregateBusinessInformationEntity:100');
        $this->xpath->registerNamespace('udt', 'urn:un:unece:uncefact:data:standard:UnqualifiedDataType:100');

        $violated = [];

        foreach ([...$this->document(), ...$this->parties(), ...$this->payment(), ...$this->lines(), ...$this->totals()] as $rule => $holds) {
            if (!$holds) {
                $violated[] = $rule;
            }
        }

        return $violated;
    }

    /**
     * @return array<string, bool>
     */
    private function document(): array
    {
        $type = $this->text('/rsm:CrossIndustryInvoice/rsm:ExchangedDocument/ram:TypeCode');
        $period = '//ram:ApplicableHeaderTradeSettlement/ram:BillingSpecifiedPeriod';
        $start = $this->text($period.'/ram:StartDateTime/udt:DateTimeString');
        $end = $this->text($period.'/ram:EndDateTime/udt:DateTimeString');

        return [
            'BR-01' => '' !== $this->text('//ram:GuidelineSpecifiedDocumentContextParameter/ram:ID'),
            'BR-DE-21' => self::SPECIFICATION === $this->text('//ram:GuidelineSpecifiedDocumentContextParameter/ram:ID'),
            'BT-23' => '' !== $this->text('//ram:BusinessProcessSpecifiedDocumentContextParameter/ram:ID'),
            'BR-02' => '' !== $this->text('/rsm:CrossIndustryInvoice/rsm:ExchangedDocument/ram:ID'),
            'BR-03' => 1 === preg_match('/^\d{8}$/', $this->text('/rsm:CrossIndustryInvoice/rsm:ExchangedDocument/ram:IssueDateTime/udt:DateTimeString')),
            'BR-04' => '' !== $type,
            'BR-DE-17' => \in_array($type, self::TYPE_CODES, true),
            'BR-05' => '' !== $this->text('//ram:InvoiceCurrencyCode'),
            'BR-DE-15' => '' !== $this->text('//ram:ApplicableHeaderTradeAgreement/ram:BuyerReference'),
            'BR-DE-26' => '384' !== $type || '' !== $this->text('//ram:InvoiceReferencedDocument/ram:IssuerAssignedID'),
            'BR-CO-19' => !$this->exists($period) || '' !== $start || '' !== $end,
            'BR-29' => '' === $start || '' === $end || $end >= $start,
        ];
    }

    /**
     * @return array<string, bool>
     */
    private function parties(): array
    {
        $seller = '//ram:ApplicableHeaderTradeAgreement/ram:SellerTradeParty';
        $buyer = '//ram:ApplicableHeaderTradeAgreement/ram:BuyerTradeParty';
        $contact = $seller.'/ram:DefinedTradeContact';
        $phone = $this->text($contact.'/ram:TelephoneUniversalCommunication/ram:CompleteNumber');
        $email = $this->text($contact.'/ram:EmailURIUniversalCommunication/ram:URIID');

        return [
            'BR-06' => '' !== $this->text($seller.'/ram:Name'),
            'BR-07' => '' !== $this->text($buyer.'/ram:Name'),
            'BR-08' => $this->exists($seller.'/ram:PostalTradeAddress'),
            'BR-09' => '' !== $this->text($seller.'/ram:PostalTradeAddress/ram:CountryID'),
            'BR-DE-3' => '' !== $this->text($seller.'/ram:PostalTradeAddress/ram:CityName'),
            'BR-DE-4' => '' !== $this->text($seller.'/ram:PostalTradeAddress/ram:PostcodeCode'),
            'BR-10' => $this->exists($buyer.'/ram:PostalTradeAddress'),
            'BR-11' => '' !== $this->text($buyer.'/ram:PostalTradeAddress/ram:CountryID'),
            'BR-DE-8' => '' !== $this->text($buyer.'/ram:PostalTradeAddress/ram:CityName'),
            'BR-DE-9' => '' !== $this->text($buyer.'/ram:PostalTradeAddress/ram:PostcodeCode'),
            'BR-DE-2' => $this->exists($contact),
            'BR-DE-5' => '' !== $this->text($contact.'/ram:PersonName'),
            'BR-DE-6' => '' !== $phone,
            'BR-DE-7' => '' !== $email,
            'BR-DE-27' => preg_match_all('/\d/', $phone) >= 3,
            'BR-DE-28' => 1 === preg_match('/^[^@\s]+@[^@\s]+\.[^@\s]+$/', $email),
            'BT-34' => '' !== $this->text($seller.'/ram:URIUniversalCommunication/ram:URIID'),
            'BR-62' => '' !== $this->attribute($seller.'/ram:URIUniversalCommunication/ram:URIID', 'schemeID'),
            'BT-49' => '' !== $this->text($buyer.'/ram:URIUniversalCommunication/ram:URIID'),
            'BR-63' => '' !== $this->attribute($buyer.'/ram:URIUniversalCommunication/ram:URIID', 'schemeID'),
            'BR-S-02' => '' !== $this->text($seller.'/ram:SpecifiedTaxRegistration/ram:ID'),
            'BR-DE-16' => \in_array($this->attribute($seller.'/ram:SpecifiedTaxRegistration/ram:ID', 'schemeID'), ['VA', 'FC'], true),
        ];
    }

    /**
     * @return array<string, bool>
     */
    private function payment(): array
    {
        $means = '//ram:ApplicableHeaderTradeSettlement/ram:SpecifiedTradeSettlementPaymentMeans';
        $code = $this->text($means.'/ram:TypeCode');
        $payee = $this->text($means.'/ram:PayeePartyCreditorFinancialAccount/ram:IBANID');
        $debtor = $this->text($means.'/ram:PayerPartyDebtorFinancialAccount/ram:IBANID');
        $terms = '//ram:ApplicableHeaderTradeSettlement/ram:SpecifiedTradePaymentTerms';

        return [
            'BR-DE-1' => $this->exists($means),
            'BR-49' => '' !== $code,
            'BR-DE-23-a' => '58' !== $code || '' !== $payee,
            'BR-DE-23-b' => '58' !== $code || '' === $debtor,
            'BR-DE-19' => '58' !== $code || self::isIban($payee),
            'BR-DE-25-a' => '59' !== $code || ('' !== $this->text($terms.'/ram:DirectDebitMandateID')
                && '' !== $this->text('//ram:ApplicableHeaderTradeSettlement/ram:CreditorReferenceID') && '' !== $debtor),
            'BR-DE-25-b' => '59' !== $code || '' === $payee,
            'BR-DE-20' => '59' !== $code || self::isIban($debtor),
            'BR-CO-25' => $this->amount('//ram:DuePayableAmount') <= 0
                || '' !== $this->text($terms.'/ram:DueDateDateTime/udt:DateTimeString') || '' !== $this->text($terms.'/ram:Description'),
        ];
    }

    /**
     * @return array<string, bool>
     */
    private function lines(): array
    {
        $rules = ['BR-16' => $this->count('//ram:IncludedSupplyChainTradeLineItem') > 0];

        foreach ($this->nodes('//ram:IncludedSupplyChainTradeLineItem') as $at => $item) {
            $in = fn (string $path): string => $this->textIn($item, $path);
            $rules += [
                'BR-21 #'.($at + 1) => '' !== $in('ram:AssociatedDocumentLineDocument/ram:LineID'),
                'BR-22 #'.($at + 1) => '' !== $in('ram:SpecifiedLineTradeDelivery/ram:BilledQuantity'),
                'BR-23 #'.($at + 1) => '' !== $in('ram:SpecifiedLineTradeDelivery/ram:BilledQuantity/@unitCode'),
                'BR-24 #'.($at + 1) => '' !== $in('ram:SpecifiedLineTradeSettlement/ram:SpecifiedTradeSettlementLineMonetarySummation/ram:LineTotalAmount'),
                'BR-25 #'.($at + 1) => '' !== $in('ram:SpecifiedTradeProduct/ram:Name'),
                'BR-26 #'.($at + 1) => '' !== $in('ram:SpecifiedLineTradeAgreement/ram:NetPriceProductTradePrice/ram:ChargeAmount'),
                'BR-27 #'.($at + 1) => (float) $in('ram:SpecifiedLineTradeAgreement/ram:NetPriceProductTradePrice/ram:ChargeAmount') >= 0,
                'BR-CO-4 #'.($at + 1) => 'S' === $in('ram:SpecifiedLineTradeSettlement/ram:ApplicableTradeTax/ram:CategoryCode'),
                'BR-S-05 #'.($at + 1) => (float) $in('ram:SpecifiedLineTradeSettlement/ram:ApplicableTradeTax/ram:RateApplicablePercent') > 0,
            ];
        }

        return $rules;
    }

    /**
     * Die Summenregeln — gerechnet in Cent, damit keine Rundung sie zufaellig bestehen laesst.
     *
     * @return array<string, bool>
     */
    private function totals(): array
    {
        $sums = '//ram:SpecifiedTradeSettlementHeaderMonetarySummation';
        $tax = '//ram:ApplicableHeaderTradeSettlement/ram:ApplicableTradeTax';
        $lines = array_sum($this->amounts('//ram:SpecifiedTradeSettlementLineMonetarySummation/ram:LineTotalAmount'));
        $basis = $this->amount($tax.'/ram:BasisAmount');
        $rate = (int) round((float) $this->text($tax.'/ram:RateApplicablePercent') * 100);

        return [
            'BR-CO-10' => $lines === $this->amount($sums.'/ram:LineTotalAmount'),
            'BR-CO-13' => $this->amount($sums.'/ram:TaxBasisTotalAmount') === $this->amount($sums.'/ram:LineTotalAmount'),
            'BR-CO-14' => $this->amount($sums.'/ram:TaxTotalAmount') === array_sum($this->amounts($tax.'/ram:CalculatedAmount')),
            'BR-CO-15' => $this->amount($sums.'/ram:GrandTotalAmount') === $this->amount($sums.'/ram:TaxBasisTotalAmount') + $this->amount($sums.'/ram:TaxTotalAmount'),
            'BR-CO-16' => $this->amount($sums.'/ram:DuePayableAmount') === $this->amount($sums.'/ram:GrandTotalAmount') - $this->amount($sums.'/ram:TotalPrepaidAmount'),
            'BR-CO-17' => $this->amount($tax.'/ram:CalculatedAmount') === intdiv(abs($basis) * $rate + 5000, 10000) * ($basis < 0 ? -1 : 1),
            'BR-S-08' => $basis === $lines,
            'BR-DEC' => [] === array_filter(
                $this->texts('//*[contains(local-name(), "Amount")]'),
                static fn (string $value): bool => 1 !== preg_match('/^-?\d+(\.\d{1,2})?$/', $value),
            ),
        ];
    }

    private static function isIban(string $iban): bool
    {
        if (1 !== preg_match('/^[A-Z]{2}\d{2}[A-Z0-9]{11,30}$/', $iban)) {
            return false;
        }

        $digits = '';

        foreach (str_split(substr($iban, 4).substr($iban, 0, 4)) as $character) {
            $digits .= ctype_alpha($character) ? (string) (\ord($character) - 55) : $character;
        }

        $remainder = 0;

        foreach (str_split($digits, 7) as $chunk) {
            $remainder = (int) ($remainder.$chunk) % 97;
        }

        return 1 === $remainder;
    }

    private function text(string $path): string
    {
        $value = $this->xpath->evaluate('string('.$path.')');

        return \is_string($value) ? trim($value) : '';
    }

    private function textIn(DOMNode $context, string $path): string
    {
        $value = $this->xpath->evaluate('string('.$path.')', $context);

        return \is_string($value) ? trim($value) : '';
    }

    /**
     * @return list<DOMNode>
     */
    private function nodes(string $path): array
    {
        $found = $this->xpath->query($path);
        $nodes = [];

        foreach (false === $found ? [] : $found as $node) {
            if ($node instanceof DOMElement || $node instanceof DOMAttr || $node instanceof DOMText) {
                $nodes[] = $node;
            }
        }

        return $nodes;
    }

    /**
     * @return list<string>
     */
    private function texts(string $path): array
    {
        return array_map(static fn (DOMNode $node): string => trim($node->textContent), $this->nodes($path));
    }

    private function attribute(string $path, string $name): string
    {
        return $this->text($path.'/@'.$name);
    }

    private function exists(string $path): bool
    {
        return $this->count($path) > 0;
    }

    private function count(string $path): int
    {
        return \count($this->nodes($path));
    }

    /** Ein Betrag in Cent — null, wenn er fehlt. */
    private function amount(string $path): int
    {
        return self::cents($this->text($path));
    }

    /**
     * @return list<int>
     */
    private function amounts(string $path): array
    {
        return array_map(self::cents(...), $this->texts($path));
    }

    private static function cents(string $value): int
    {
        if (1 !== preg_match('/^(-?)(\d+)(?:\.(\d{1,2}))?$/', $value, $parts)) {
            return 0;
        }

        $cents = (int) $parts[2] * 100 + (int) str_pad($parts[3] ?? '', 2, '0');

        return '-' === $parts[1] ? -$cents : $cents;
    }
}
