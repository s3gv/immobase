<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Shared\EInvoice;

/**
 * Wie gezahlt wird — Ueberweisung oder Lastschrift (BG-16).
 *
 * Bei einer Ueberweisung das Konto, auf das gezahlt wird (BG-17). Bei einer
 * Lastschrift das Mandat, die Glaeubiger-ID und das Konto, von dem
 * eingezogen wird (BG-19) — damit der Zahlende den Einzug zuordnen kann.
 */
final readonly class EInvoicePayment
{
    /** SEPA-Ueberweisung (UNTDID 4461). */
    public const string CREDIT_TRANSFER = '58';

    /** SEPA-Lastschrift (UNTDID 4461). */
    public const string DIRECT_DEBIT = '59';

    /** Zahlungsweg nicht festgelegt (UNTDID 4461). */
    public const string NOT_DEFINED = '1';

    private function __construct(
        public string $code,
        public string $terms,
        public string $payeeIban = '',
        public string $payeeName = '',
        public string $mandate = '',
        public string $creditorId = '',
        public string $debtorIban = '',
    ) {
    }

    public static function transfer(string $terms, string $iban, string $accountName): self
    {
        return new self(self::CREDIT_TRANSFER, $terms, payeeIban: self::compact($iban), payeeName: $accountName);
    }

    /**
     * Kein Zahlungsweg, den die Rechnung vorgeben koennte.
     *
     * Fuer ein Guthaben, dessen Empfaengerkonto nicht bekannt ist, und fuer
     * einen Beleg, auf dem nichts mehr zu zahlen bleibt: ein Konto des
     * Verkaeufers stuende sonst da, als solle der Kaeufer dorthin
     * ueberweisen.
     */
    public static function notDefined(string $terms): self
    {
        return new self(self::NOT_DEFINED, $terms);
    }

    public function isCreditTransfer(): bool
    {
        return self::CREDIT_TRANSFER === $this->code;
    }

    public static function directDebit(string $terms, string $mandate, string $creditorId, string $debtorIban): self
    {
        return new self(self::DIRECT_DEBIT, $terms, mandate: $mandate, creditorId: $creditorId, debtorIban: self::compact($debtorIban));
    }

    public function isDirectDebit(): bool
    {
        return self::DIRECT_DEBIT === $this->code;
    }

    private static function compact(string $iban): string
    {
        return strtoupper(str_replace(' ', '', $iban));
    }
}
