<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Property\Domain;

use Doctrine\ORM\Mapping as ORM;

/**
 * Was eine Abrechnung von diesem Objekt braucht.
 *
 * Zwei Angaben, kein gemeinsamer Begriff: das Wirtschaftsjahr sagt, welcher
 * Zeitraum abgerechnet wird, das Konto, worueber das Geld laeuft. Sie stehen
 * hier zusammen, weil jede Abrechnung beide erfragt — geaendert wird jede
 * fuer sich, jede in ihrem eigenen Schritt und mit ihrer eigenen Ableitung.
 *
 * Deshalb zwei Ableitungen und kein Setzen von aussen: wer das Konto
 * eintraegt, soll nicht das Wirtschaftsjahr mitliefern muessen und es dabei
 * versehentlich zuruecksetzen koennen.
 */
#[ORM\Embeddable]
final class Accounting
{
    #[ORM\Embedded(class: FiscalYear::class, columnPrefix: false)]
    private FiscalYear $fiscalYear;

    #[ORM\Embedded(class: BankAccount::class, columnPrefix: false)]
    private BankAccount $account;

    private function __construct(FiscalYear $fiscalYear, BankAccount $account)
    {
        $this->fiscalYear = $fiscalYear;
        $this->account = $account;
    }

    /** Das Kalenderjahr und noch kein Konto. */
    public static function initial(): self
    {
        return new self(FiscalYear::calendar(), BankAccount::unknown());
    }

    public function fiscalYear(): FiscalYear
    {
        return $this->fiscalYear;
    }

    public function account(): BankAccount
    {
        return $this->account;
    }

    public function beginningOn(FiscalYear $fiscalYear): self
    {
        return new self($fiscalYear, $this->account);
    }

    public function collectedVia(BankAccount $account): self
    {
        return new self($this->fiscalYear, $account);
    }
}
