<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Tenancy\UserInterface\Controller;

use App\Module\Tenancy\Domain\EInvoiceTerms;
use App\Shared\Bank\Iban;
use App\Shared\Bank\NotAnIban;
use App\Shared\Contact\Email;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\Request;

/**
 * Was aus dem Abschnitt „E-Rechnung" des Miete-Schritts kommt.
 *
 * Alles freiwillig, aber geprueft, sobald etwas darin steht: die Adresse als
 * E-Mail-Adresse, die IBAN ueber ihre Pruefziffer, die Mandatsreferenz auf
 * die Zeichen, die SEPA kennt. Gespeichert wird nur, wenn alles durchgeht —
 * eine halb uebernommene Lastschrift waere eine, die beim Einzug scheitert.
 */
final readonly class EInvoiceTermsInput
{
    private function __construct()
    {
    }

    /**
     * @return array{errors: array<string, string>, terms: EInvoiceTerms}
     */
    public static function read(Request $request): array
    {
        $errors = [];
        $address = null;
        $iban = null;

        try {
            $given = trim($request->request->getString('buyerEAddress'));
            $address = '' === $given ? null : Email::fromString($given);
        } catch (InvalidArgumentException) {
            $errors['buyerEAddress'] = 'tenancy.error.e_address';
        }

        try {
            $iban = Iban::orNull($request->request->getString('debtorIban'));
        } catch (NotAnIban) {
            $errors['debtorIban'] = 'tenancy.error.debtor_iban';
        }

        try {
            $terms = EInvoiceTerms::of($request->request->getString('buyerReference'), $address, $request->request->getString('sepaMandate'), $iban);
        } catch (InvalidArgumentException) {
            $errors['sepaMandate'] = 'tenancy.error.sepa_mandate';
            $terms = EInvoiceTerms::none();
        }

        return ['errors' => $errors, 'terms' => $terms];
    }
}
