<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Tenancy\Domain;

use App\Shared\Bank\Iban;
use App\Shared\Contact\Email;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use InvalidArgumentException;

/**
 * Was der Mieter fuer eine E-Rechnung angeben muss.
 *
 * Alles davon kommt **vom Mieter** und nichts wird geraten: die Referenz, unter
 * der seine Buchhaltung die Rechnung zuordnet — bei Behoerden die Leitweg-ID —,
 * und die Adresse, an die sie elektronisch geht. Zahlt er per Lastschrift,
 * gehoeren Mandatsreferenz und seine IBAN dazu; die E-Rechnung nennt den
 * Einzug, damit er ihn wiederfindet.
 *
 * Leer ist erlaubt. Gebraucht werden die Angaben erst, wenn eine Rechnung mit
 * Umsatzsteuer ausgestellt wird — dort fehlen sie dann benannt.
 */
#[ORM\Embeddable]
final class EInvoiceTerms
{
    /** Erlaubte Zeichen einer Mandatsreferenz nach SEPA-Regelwerk. */
    private const string MANDATE = "/^[A-Za-z0-9+?\\/\\-:().,' ]{1,35}$/D";

    #[ORM\Column(name: 'buyer_reference', type: Types::STRING, length: 100)]
    private string $buyerReference = '';

    #[ORM\Column(name: 'buyer_e_address', type: Types::STRING, length: 200)]
    private string $buyerEAddress = '';

    #[ORM\Column(name: 'sepa_mandate', type: Types::STRING, length: 35)]
    private string $sepaMandate = '';

    #[ORM\Column(name: 'debtor_iban', type: Types::STRING, length: 34)]
    private string $debtorIban = '';

    private function __construct()
    {
    }

    public static function none(): self
    {
        return new self();
    }

    /**
     * @throws InvalidArgumentException bei einer Mandatsreferenz mit Zeichen, die SEPA nicht kennt, oder einer zu langen Referenz
     */
    public static function of(string $buyerReference, ?Email $buyerEAddress, string $sepaMandate, ?Iban $debtorIban): self
    {
        $terms = new self();
        $terms->buyerReference = trim($buyerReference);
        $terms->buyerEAddress = $buyerEAddress?->toString() ?? '';
        $terms->sepaMandate = trim($sepaMandate);
        $terms->debtorIban = $debtorIban?->toString() ?? '';

        if (mb_strlen($terms->buyerReference) > 100) {
            throw new InvalidArgumentException('Die Käuferreferenz ist zu lang.');
        }

        if ('' !== $terms->sepaMandate && 1 !== preg_match(self::MANDATE, $terms->sepaMandate)) {
            throw new InvalidArgumentException('Die Mandatsreferenz enthält Zeichen, die SEPA nicht kennt.');
        }

        return $terms;
    }

    public function buyerReference(): string
    {
        return $this->buyerReference;
    }

    public function buyerEAddress(): string
    {
        return $this->buyerEAddress;
    }

    public function sepaMandate(): string
    {
        return $this->sepaMandate;
    }

    public function debtorIban(): string
    {
        return $this->debtorIban;
    }

    public function debtorIbanInGroups(): string
    {
        return trim(chunk_split($this->debtorIban, 4, ' '));
    }
}
