<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Tests\Shared\EInvoice;

use DOMDocument;
use LibXMLError;

/**
 * Das CII-Schema D16B — die Gestalt, die eine XRechnung in CII haben muss.
 *
 * Die Schemadateien liegen unveraendert unter `tests/Fixture/xsd/cii-d16b/`,
 * so wie UN/CEFACT sie herausgibt und die EU-Referenzimplementierung der Norm
 * sie fuehrt. Das Schema prueft Elemente, Reihenfolge und Datentypen; was
 * darueber hinaus gilt, prueft {@see XRechnungRules}.
 */
final class CiiSchema
{
    /**
     * @return list<string> die Meldungen des Schemas — leer, wenn es passt
     */
    public static function violations(string $xml): array
    {
        $previous = libxml_use_internal_errors(true);
        libxml_clear_errors();

        $document = new DOMDocument();
        $valid = $document->loadXML($xml) && $document->schemaValidate(self::file());

        $messages = array_map(
            static fn (LibXMLError $error): string => \sprintf('Zeile %d: %s', $error->line, trim($error->message)),
            libxml_get_errors(),
        );

        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        return $valid && [] === $messages ? [] : ([] === $messages ? ['ungültig'] : array_values($messages));
    }

    private static function file(): string
    {
        return \dirname(__DIR__, 2).'/Fixture/xsd/cii-d16b/CrossIndustryInvoice_100pD16B.xsd';
    }
}
