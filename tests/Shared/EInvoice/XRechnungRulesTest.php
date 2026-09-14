<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Tests\Shared\EInvoice;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Die nachgebildeten Regeln greifen — an genau der Stelle, an der sie sollen.
 *
 * Eine Pruefung, die nie etwas findet, ist keine. Jede Zeile hier verdirbt
 * eine gueltige Referenzdatei an einer Stelle und erwartet genau die Regel,
 * die das verbietet.
 */
final class XRechnungRulesTest extends TestCase
{
    /**
     * @param non-empty-string $from
     */
    #[DataProvider('spoiled')]
    public function testASpoiledFileBreaksItsRule(string $file, string $from, string $to, string $rule): void
    {
        $xml = (string) file_get_contents(\dirname(__DIR__, 2).'/Fixture/xrechnung/'.$file);
        self::assertStringContainsString($from, $xml, 'Die Stelle, die verdorben wird, gibt es');

        self::assertContains($rule, (new XRechnungRules())->violations(str_replace($from, $to, $xml)));
    }

    /**
     * @return iterable<string, array{string, non-empty-string, string, string}>
     */
    public static function spoiled(): iterable
    {
        $transfer = 'writer-ueberweisung.xml';
        $debit = 'writer-lastschrift.xml';
        $credit = 'writer-korrektur-guthaben.xml';

        yield 'falsche Spezifikation' => [$transfer, 'xrechnung_3.0</ram:ID>', 'xrechnung_2.3</ram:ID>', 'BR-DE-21'];
        yield 'ohne Käuferreferenz' => [$transfer, '<ram:BuyerReference>LADEN-4711</ram:BuyerReference>', '', 'BR-DE-15'];
        yield 'ohne Telefon' => [$transfer, '<ram:CompleteNumber>0211 4711 00</ram:CompleteNumber>', '<ram:CompleteNumber></ram:CompleteNumber>', 'BR-DE-6'];
        yield 'ohne Adresse des Käufers' => [$transfer, 'schemeID="EM">laden-rechnung@example.org', 'schemeID="EM">', 'BT-49'];
        yield 'ohne Postleitzahl des Verkäufers' => [$transfer, '<ram:PostcodeCode>40233</ram:PostcodeCode>', '', 'BR-DE-4'];
        yield 'Zeilen gehen nicht auf' => [$transfer, '<ram:LineTotalAmount>1800.00</ram:LineTotalAmount>', '<ram:LineTotalAmount>1800.01</ram:LineTotalAmount>', 'BR-CO-10'];
        yield 'Steuer falsch gerundet' => [$transfer, '<ram:CalculatedAmount>437.00</ram:CalculatedAmount>', '<ram:CalculatedAmount>437.01</ram:CalculatedAmount>', 'BR-CO-17'];
        yield 'IBAN mit Zahlendreher' => [$transfer, '<ram:IBANID>DE02120300000000202051</ram:IBANID>', '<ram:IBANID>DE02120300000000202015</ram:IBANID>', 'BR-DE-19'];
        yield 'ohne Zahlungsbedingung' => [$transfer, '<ram:Description>Zahlbar monatlich im Voraus, spätestens am dritten Werktag des Monats.</ram:Description>', '', 'BR-CO-25'];
        yield 'Lastschrift ohne Mandat' => [$debit, '<ram:DirectDebitMandateID>MANDAT-2026/001</ram:DirectDebitMandateID>', '', 'BR-DE-25-a'];
        yield 'Lastschrift ohne Gläubiger-ID' => [$debit, '<ram:CreditorReferenceID>DE98ZZZ09999999999</ram:CreditorReferenceID>', '', 'BR-DE-25-a'];
        yield 'Korrektur ohne Verweis' => [$credit, '<ram:IssuerAssignedID>NK-20001/1-2026-7-1</ram:IssuerAssignedID>', '', 'BR-DE-26'];
        yield 'Zahlbetrag ohne Vorauszahlung' => [$credit, '<ram:TotalPrepaidAmount>2142.00</ram:TotalPrepaidAmount>', '', 'BR-CO-16'];
        yield 'drei Nachkommastellen' => [$credit, '<ram:GrandTotalAmount>1051.60</ram:GrandTotalAmount>', '<ram:GrandTotalAmount>1051.600</ram:GrandTotalAmount>', 'BR-DEC'];
    }
}
