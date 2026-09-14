<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Property\Domain;

use App\Shared\Bank\Bic;
use App\Shared\Bank\CreditorId;
use App\Shared\Bank\Iban;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Das Konto, ueber das dieses Objekt laeuft.
 *
 * Ein Konto je Objekt, nicht mehr: das ist der Normalfall, und das zweite
 * Konto kommt mit dem Fall, der es braucht. Bis dahin waere eine Liste eine
 * Frage, die sich niemand gestellt hat.
 *
 * Alle Angaben sind freiwillig — ein Objekt entsteht lange bevor sein Konto
 * feststeht. Was aber dasteht, wird geprueft: IBAN und BIC kommen als
 * Wertobjekte herein, also ist hier nichts anzuzweifeln.
 *
 * Der Kontoinhaber steht eigens da und wird nicht aus dem Objektnamen
 * abgeleitet. Bei einer WEG lautet er auf die Gemeinschaft, bei einer
 * Mietverwaltung oft auf den Eigentuemer, und die Bank vergleicht ihn.
 */
#[ORM\Embeddable]
final class BankAccount
{
    #[ORM\Column(name: 'bank_iban', type: Types::STRING, length: 34)]
    private string $iban = '';

    #[ORM\Column(name: 'bank_bic', type: Types::STRING, length: 11)]
    private string $bic = '';

    #[ORM\Column(name: 'bank_holder', type: Types::STRING, length: 200)]
    private string $holder = '';

    /** Wofuer das Konto da ist — „Hausgeldkonto", „Mietkonto". */
    #[ORM\Column(name: 'bank_label', type: Types::STRING, length: 120)]
    private string $label = '';

    /**
     * Die Glaeubiger-ID — nur, wer von diesem Konto Lastschriften einzieht.
     *
     * Eine E-Rechnung ueber eine Lastschrift nennt sie (BT-90).
     */
    #[ORM\Column(name: 'bank_creditor_id', type: Types::STRING, length: 35)]
    private string $creditorId = '';

    private function __construct()
    {
    }

    public static function unknown(): self
    {
        return new self();
    }

    public static function of(?Iban $iban, ?Bic $bic, string $holder, string $label, ?CreditorId $creditorId = null): self
    {
        $account = new self();
        $account->iban = $iban?->toString() ?? '';
        $account->bic = $bic?->toString() ?? '';
        $account->holder = trim($holder);
        $account->label = trim($label);
        $account->creditorId = $creditorId?->toString() ?? '';

        return $account;
    }

    public function creditorId(): string
    {
        return $this->creditorId;
    }

    public function iban(): string
    {
        return $this->iban;
    }

    /** Die IBAN, wie sie auf Papier steht: in Vierergruppen. */
    public function ibanInGroups(): string
    {
        return trim(chunk_split($this->iban, 4, ' '));
    }

    public function bic(): string
    {
        return $this->bic;
    }

    public function holder(): string
    {
        return $this->holder;
    }

    public function label(): string
    {
        return $this->label;
    }

    /** Ohne IBAN ist es kein Konto, sondern eine Absicht. */
    public function isKnown(): bool
    {
        return '' !== $this->iban;
    }
}
