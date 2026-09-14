<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Property\UserInterface\Controller;

use App\Module\Property\Application\SaveProperty;
use App\Module\Property\Domain\BankAccount;
use App\Module\Property\Domain\Property;
use App\Shared\Bank\Bic;
use App\Shared\Bank\CreditorId;
use App\Shared\Bank\Iban;
use App\Shared\Bank\NotABic;
use App\Shared\Bank\NotACreditorId;
use App\Shared\Bank\NotAnIban;
use Symfony\Component\HttpFoundation\Request;

/**
 * Was aus dem Bankkonto-Schritt kommt.
 *
 * Ein eigener Schritt und eine eigene Klasse: alles daran ist freiwillig,
 * aber IBAN und BIC werden geprueft, sobald etwas darin steht — die IBAN
 * ueber ihre Pruefziffer, die BIC ueber ihre Gestalt. Dieselbe Pruefung wie
 * bei der Organisation in den Einstellungen; die Wertobjekte liegen deshalb
 * in Shared.
 *
 * Gespeichert wird erst, wenn beide durchgehen. Ein halb uebernommenes Konto
 * waere schlimmer als eines, das zurueckkommt: danach stuende eine IBAN ohne
 * ihre BIC da, und niemand saehe, dass etwas fehlt.
 */
final readonly class BankAccountInput
{
    public function __construct(private SaveProperty $save)
    {
    }

    /**
     * @return array<string, string> Feldname auf Uebersetzungsschluessel
     */
    public function apply(Request $request, Property $property): array
    {
        $read = self::read($request);

        if ([] !== $read['errors']) {
            return $read['errors'];
        }

        $this->save->collectsVia($property, $read['account']);

        return [];
    }

    /**
     * @return array{errors: array<string, string>, account: BankAccount}
     */
    private static function read(Request $request): array
    {
        $errors = [];

        try {
            $iban = Iban::orNull($request->request->getString('iban'));
        } catch (NotAnIban) {
            [$iban, $errors['iban']] = [null, 'property.error.iban'];
        }

        try {
            $bic = Bic::orNull($request->request->getString('bic'));
        } catch (NotABic) {
            [$bic, $errors['bic']] = [null, 'property.error.bic'];
        }

        $creditorId = self::creditorId($request, $errors);

        return [
            'errors' => $errors,
            'account' => BankAccount::of(
                $iban,
                $bic,
                $request->request->getString('holder'),
                $request->request->getString('label'),
                $creditorId,
            ),
        ];
    }

    /**
     * @param array<string, string> $errors
     */
    private static function creditorId(Request $request, array &$errors): ?CreditorId
    {
        try {
            return CreditorId::orNull($request->request->getString('creditorId'));
        } catch (NotACreditorId) {
            $errors['creditorId'] = 'property.error.creditor_id';

            return null;
        }
    }
}
