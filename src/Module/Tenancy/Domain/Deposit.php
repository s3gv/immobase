<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Tenancy\Domain;

use App\Shared\Money\Money;
use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use InvalidArgumentException;

/**
 * Die Kaution: Hoehe, Form, Eingang.
 *
 * Ohne Betrag gibt es keine Kaution. Form und Eingangsdatum allein wuerden
 * eine Kaution behaupten, die niemand beziffern kann — und die faellt beim
 * Auszug auf, wenn niemand mehr weiss, wie viel es war.
 *
 * Und keine negative: MoneyInput laesst ein Vorzeichen zu, weil eine Buchung
 * negativ sein kann. Eine hinterlegte Sicherheit nicht — „minus 1.200 Euro
 * Kaution" ist ein Vertipper und stuende beim Auszug als Forderung da.
 *
 * Bewusst kein eigener Datensatz mit Zinslauf und Rueckzahlung: das ist
 * Buchhaltung und gehoert ins Zahlungsmodul.
 */
#[ORM\Embeddable]
final class Deposit
{
    #[ORM\Column(name: 'deposit_amount', type: Types::BIGINT, nullable: true)]
    private ?int $amount = null;

    #[ORM\Column(name: 'deposit_kind', type: Types::STRING, length: 16, nullable: true, enumType: DepositKind::class)]
    private ?DepositKind $kind = null;

    #[ORM\Column(name: 'deposit_received_on', type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?DateTimeImmutable $receivedOn = null;

    private function __construct()
    {
    }

    public static function none(): self
    {
        return new self();
    }

    /**
     * @throws InvalidArgumentException
     */
    public static function of(?Money $amount, ?DepositKind $kind, ?DateTimeImmutable $receivedOn): self
    {
        if (null !== $amount && $amount->isNegative()) {
            throw new InvalidArgumentException('Eine Kaution kann nicht negativ sein.');
        }

        $deposit = new self();

        if (null === $amount || $amount->isZero()) {
            return $deposit;
        }

        $deposit->amount = $amount->cents();
        $deposit->kind = $kind;
        $deposit->receivedOn = $receivedOn;

        return $deposit;
    }

    public function isAgreed(): bool
    {
        return null !== $this->amount;
    }

    public function amount(): ?Money
    {
        return null === $this->amount ? null : Money::fromCents($this->amount);
    }

    public function kind(): ?DepositKind
    {
        return $this->kind;
    }

    public function receivedOn(): ?DateTimeImmutable
    {
        return $this->receivedOn;
    }

    /** Vereinbart, aber noch nicht da — der Fall, der auffallen soll. */
    public function isOutstanding(): bool
    {
        return $this->isAgreed() && null === $this->receivedOn;
    }
}
