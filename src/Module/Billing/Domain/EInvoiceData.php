<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Billing\Domain;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Was eine E-Rechnung ueber das Papier hinaus braucht — eingefroren.
 *
 * Auf dem Blatt steht die Anschrift als Block; eine XRechnung will sie in
 * Teilen (BT-35 bis BT-40). Dazu die Referenz und die Adresse des Mieters
 * (BT-10, BT-49), der Ansprechpartner der Verwaltung und ihre Adresse fuer
 * Antworten (BG-6, BT-34), und wie gezahlt wird — bei einer Lastschrift mit
 * Mandat, Glaeubiger-ID und der IBAN des Mieters (BT-89 bis BT-91).
 *
 * Eingefroren am Tag der Ausstellung wie alles andere: wer spaeter die
 * Verwaltung wechselt oder sein Rechnungspostfach, aendert damit keine
 * zugestellte Rechnung. Aeltere Belege tragen es nicht — dort ist
 * {@see self::isCaptured()} falsch, und eine E-Rechnung gibt es erst mit einer
 * neuen Fassung.
 */
#[ORM\Embeddable]
final class EInvoiceData
{
    #[ORM\Column(name: 'einvoice_captured', type: Types::BOOLEAN)]
    private bool $captured = false;

    #[ORM\Column(name: 'seller_street', type: Types::STRING, length: 200)]
    private string $sellerStreet = '';

    #[ORM\Column(name: 'seller_postal_code', type: Types::STRING, length: 20)]
    private string $sellerPostalCode = '';

    #[ORM\Column(name: 'seller_city', type: Types::STRING, length: 100)]
    private string $sellerCity = '';

    #[ORM\Column(name: 'buyer_street', type: Types::STRING, length: 200)]
    private string $buyerStreet = '';

    #[ORM\Column(name: 'buyer_postal_code', type: Types::STRING, length: 20)]
    private string $buyerPostalCode = '';

    #[ORM\Column(name: 'buyer_city', type: Types::STRING, length: 100)]
    private string $buyerCity = '';

    #[ORM\Column(name: 'buyer_reference', type: Types::STRING, length: 100)]
    private string $buyerReference = '';

    #[ORM\Column(name: 'buyer_e_address', type: Types::STRING, length: 200)]
    private string $buyerEAddress = '';

    #[ORM\Column(name: 'contact_name', type: Types::STRING, length: 200)]
    private string $contactName = '';

    #[ORM\Column(name: 'contact_phone', type: Types::STRING, length: 60)]
    private string $contactPhone = '';

    #[ORM\Column(name: 'contact_email', type: Types::STRING, length: 200)]
    private string $contactEmail = '';

    /** `transfer` oder `direct_debit` */
    #[ORM\Column(name: 'payment_method', type: Types::STRING, length: 16)]
    private string $paymentMethod = 'transfer';

    /** `third_working_day`, `month_start`, `month_end` — leer bei einer Abrechnung */
    #[ORM\Column(name: 'payment_due', type: Types::STRING, length: 24)]
    private string $paymentDue = '';

    #[ORM\Column(name: 'sepa_mandate', type: Types::STRING, length: 35)]
    private string $sepaMandate = '';

    #[ORM\Column(name: 'debtor_iban', type: Types::STRING, length: 34)]
    private string $debtorIban = '';

    #[ORM\Column(name: 'creditor_id', type: Types::STRING, length: 35)]
    private string $creditorId = '';

    private function __construct()
    {
    }

    /** Ein Beleg ohne E-Rechnung — steuerfrei, ein Entwurf oder von vorher. */
    public static function none(): self
    {
        return new self();
    }

    /**
     * Was beim Ausstellen feststand.
     *
     * @param array{street: string, postalCode: string, city: string}                                     $seller
     * @param array{street: string, postalCode: string, city: string}                                     $buyer
     * @param array{reference: string, eAddress: string}                                                  $recipient
     * @param array{name: string, phone: string, email: string}                                           $contact
     * @param array{method: string, due: string, mandate: string, debtorIban: string, creditorId: string} $payment
     */
    public static function of(array $seller, array $buyer, array $recipient, array $contact, array $payment): self
    {
        $data = new self();
        $data->captured = true;
        [$data->sellerStreet, $data->sellerPostalCode, $data->sellerCity] = [$seller['street'], $seller['postalCode'], $seller['city']];
        [$data->buyerStreet, $data->buyerPostalCode, $data->buyerCity] = [$buyer['street'], $buyer['postalCode'], $buyer['city']];
        [$data->buyerReference, $data->buyerEAddress] = [$recipient['reference'], $recipient['eAddress']];
        [$data->contactName, $data->contactPhone, $data->contactEmail] = [$contact['name'], $contact['phone'], $contact['email']];
        [$data->paymentMethod, $data->paymentDue] = [$payment['method'], $payment['due']];
        [$data->sepaMandate, $data->debtorIban, $data->creditorId] = [$payment['mandate'], $payment['debtorIban'], $payment['creditorId']];

        return $data;
    }

    public function isCaptured(): bool
    {
        return $this->captured;
    }

    /** @return array{street: string, postalCode: string, city: string} */
    public function seller(): array
    {
        return ['street' => $this->sellerStreet, 'postalCode' => $this->sellerPostalCode, 'city' => $this->sellerCity];
    }

    /** @return array{street: string, postalCode: string, city: string} */
    public function buyer(): array
    {
        return ['street' => $this->buyerStreet, 'postalCode' => $this->buyerPostalCode, 'city' => $this->buyerCity];
    }

    public function buyerReference(): string
    {
        return $this->buyerReference;
    }

    public function buyerEAddress(): string
    {
        return $this->buyerEAddress;
    }

    /** @return array{name: string, phone: string, email: string} */
    public function contact(): array
    {
        return ['name' => $this->contactName, 'phone' => $this->contactPhone, 'email' => $this->contactEmail];
    }

    public function isDirectDebit(): bool
    {
        return 'direct_debit' === $this->paymentMethod;
    }

    public function paymentDue(): string
    {
        return $this->paymentDue;
    }

    /** @return array{mandate: string, debtorIban: string, creditorId: string} */
    public function directDebit(): array
    {
        return ['mandate' => $this->sepaMandate, 'debtorIban' => $this->debtorIban, 'creditorId' => $this->creditorId];
    }
}
