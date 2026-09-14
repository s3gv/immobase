<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Billing\Application;

use App\Module\Billing\Domain\EInvoiceData;
use App\Module\Party\Contract\PartyBrief;
use App\Module\Party\Contract\PartyDirectory;
use App\Module\Property\Contract\PropertyBrief;
use App\Module\Settings\Contract\ApplicationSettings;
use App\Module\Tenancy\Contract\TenancyBrief;

/**
 * Zusammentragen, was eine E-Rechnung ueber das Papier hinaus braucht.
 *
 * Aus vier Modulen, an einer Stelle: die Anschriften in Teilen aus den
 * Stammdaten, Referenz und Zahlungsabrede aus dem Mietverhaeltnis, die
 * Glaeubiger-ID vom Konto des Objekts und der Ansprechpartner aus den
 * Einstellungen. Die Dauermietrechnung und die Abrechnung stellen dieselbe
 * Frage — zwei Antworten darauf hiessen, dass derselbe Mieter auf zwei
 * E-Rechnungen verschieden adressiert waere.
 *
 * Verkaeufer und Kaeufer sind wie auf dem Blatt der jeweils erste: dieselbe
 * Regel wie in {@see Addressed}.
 */
final readonly class CaptureEInvoiceData
{
    public function __construct(
        private PartyDirectory $parties,
        private ApplicationSettings $settings,
    ) {
    }

    /**
     * @param list<string> $sellerPartyIds
     * @param list<string> $buyerPartyIds
     * @param bool         $withDueDay     die Dauermietrechnung nennt den Faelligkeitstag, die Abrechnung nicht
     */
    public function of(
        array $sellerPartyIds,
        array $buyerPartyIds,
        ?TenancyBrief $tenancy,
        ?PropertyBrief $property,
        bool $withDueDay,
    ): EInvoiceData {
        $organisation = $this->settings->organisation();

        return EInvoiceData::of(
            seller: $this->postalOf($sellerPartyIds),
            buyer: $this->postalOf($buyerPartyIds),
            recipient: ['reference' => $tenancy->buyerReference ?? '', 'eAddress' => $tenancy->buyerEAddress ?? ''],
            contact: ['name' => $organisation->name, 'phone' => $organisation->phone, 'email' => $organisation->email],
            payment: [
                'method' => $tenancy->paymentMethod ?? 'transfer',
                'due' => $withDueDay ? ($tenancy->paymentDue ?? '') : '',
                'mandate' => $tenancy->sepaMandate ?? '',
                'debtorIban' => $tenancy->debtorIban ?? '',
                'creditorId' => $property->creditorId ?? '',
            ],
        );
    }

    /**
     * @param list<string> $partyIds
     *
     * @return array{street: string, postalCode: string, city: string}
     */
    private function postalOf(array $partyIds): array
    {
        $known = $this->parties->byIds($partyIds);
        $first = null;

        foreach ($partyIds as $id) {
            $first ??= $known[$id] ?? null;
        }

        $postal = $first instanceof PartyBrief ? $first->postal : ['line' => '', 'postalCode' => '', 'city' => ''];

        return ['street' => $postal['line'], 'postalCode' => $postal['postalCode'], 'city' => $postal['city']];
    }
}
