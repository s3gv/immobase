<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Dunning\Domain;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Wer schreibt an wen, und wohin gezahlt wird — eingefroren.
 *
 * Der Glaeubiger mit Anschrift, der Schuldner mit Anschrift und das Konto.
 * Nicht die Verwaltung — die steht im Briefkopf, weil das Schreiben von dort
 * kommt, aber gefordert wird im Namen der Gemeinschaft oder des Vermieters.
 *
 * Eingefroren, weil ein zugestelltes Schreiben nicht die Anschrift von heute
 * traegt, sondern die von damals. Wer wissen will, wohin es ging, muss es
 * hier ablesen koennen.
 *
 * **Die IBAN steht aus demselben Grund hier.** Sie stand auf dem Blatt, das
 * der Schuldner in der Hand hat. Wechselt das Objekt danach die Bank, zeigte
 * ein nachtraeglich gelesenes Konto eine Zahlungsanweisung, die so nie
 * hinausging — und wer auf das alte Konto ueberwies, haette nach diesem Beleg
 * an die falsche Stelle gezahlt.
 */
#[ORM\Embeddable]
final class Recipients
{
    #[ORM\Column(name: 'creditor_name', type: Types::STRING, length: 400)]
    private string $creditorName;

    #[ORM\Column(name: 'creditor_address', type: Types::STRING, length: 400)]
    private string $creditorAddress;

    #[ORM\Column(name: 'debtor_name', type: Types::STRING, length: 400)]
    private string $debtorName;

    #[ORM\Column(name: 'debtor_address', type: Types::STRING, length: 400)]
    private string $debtorAddress;

    #[ORM\Column(name: 'payee_iban', type: Types::STRING, length: 34)]
    private string $payeeIban;

    private function __construct(
        string $creditorName,
        string $creditorAddress,
        string $debtorName,
        string $debtorAddress,
        string $payeeIban,
    ) {
        $this->creditorName = $creditorName;
        $this->creditorAddress = $creditorAddress;
        $this->debtorName = $debtorName;
        $this->debtorAddress = $debtorAddress;
        $this->payeeIban = $payeeIban;
    }

    public static function nobody(): self
    {
        return new self('', '', '', '', '');
    }

    public static function of(
        string $creditorName,
        string $creditorAddress,
        string $debtorName,
        string $debtorAddress,
        string $payeeIban,
    ): self {
        return new self($creditorName, $creditorAddress, $debtorName, $debtorAddress, $payeeIban);
    }

    public function creditorName(): string
    {
        return $this->creditorName;
    }

    public function creditorAddress(): string
    {
        return $this->creditorAddress;
    }

    public function debtorName(): string
    {
        return $this->debtorName;
    }

    public function debtorAddress(): string
    {
        return $this->debtorAddress;
    }

    public function payeeIban(): string
    {
        return $this->payeeIban;
    }
}
