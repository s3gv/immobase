<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Finance\Domain;

use App\Shared\Money\Money;
use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Was mit der Bank vereinbart ist.
 *
 * Summe, Zins, erste Rate — und dann **entweder** die Rate **oder** die
 * Laufzeit. Die Bank nennt meistens die Rate; nennt sie die Laufzeit, wird
 * die Rate gesucht. Beides einzutragen hiesse, zwei Angaben zu haben, die
 * sich widersprechen koennen, und die Datenbank besteht darauf, dass genau
 * eine dasteht.
 *
 * Der Zinssatz steht in Basispunkten: 4,20 % sind 420.
 */
#[ORM\Embeddable]
final class LoanTerms
{
    #[ORM\Column(name: 'loan_amount', type: Types::BIGINT)]
    private int $amount;

    #[ORM\Column(name: 'rate_bps', type: Types::INTEGER)]
    private int $rateBps;

    /** Der Tag der ersten Rate — ab ihm zaehlt der Plan. */
    #[ORM\Column(name: 'starts_on', type: Types::DATE_IMMUTABLE)]
    private DateTimeImmutable $startsOn;

    #[ORM\Column(name: 'payment', type: Types::BIGINT, nullable: true)]
    private ?int $payment;

    #[ORM\Column(name: 'months', type: Types::SMALLINT, nullable: true)]
    private ?int $months;

    private function __construct(int $amount, int $rateBps, DateTimeImmutable $startsOn, ?int $payment, ?int $months)
    {
        $this->amount = $amount;
        $this->rateBps = $rateBps;
        $this->startsOn = $startsOn;
        $this->payment = $payment;
        $this->months = $months;
    }

    /** Mit bekannter Rate — der uebliche Fall. */
    public static function withPayment(Money $amount, int $rateBps, DateTimeImmutable $startsOn, Money $payment): self
    {
        return new self(max(0, $amount->cents()), self::sane($rateBps), $startsOn, max(1, $payment->cents()), null);
    }

    /** Mit bekannter Laufzeit — die Rate rechnet sich. */
    public static function overMonths(Money $amount, int $rateBps, DateTimeImmutable $startsOn, int $months): self
    {
        return new self(max(0, $amount->cents()), self::sane($rateBps), $startsOn, null, max(1, min(600, $months)));
    }

    public function amount(): Money
    {
        return Money::fromCents($this->amount);
    }

    /**
     * Steht ueberhaupt noch nichts fest?
     *
     * Beim Anlegen haelt eine Null die Stelle, bis jemand den Vertrag vor
     * sich hat. Das Formular soll dann leere Felder zeigen und keine
     * Nullen — eine Null ist eine Angabe, und diese hat niemand gemacht.
     */
    public function areOpen(): bool
    {
        return 0 === $this->amount;
    }

    public function rateBps(): int
    {
        return $this->rateBps;
    }

    public function startsOn(): DateTimeImmutable
    {
        return $this->startsOn;
    }

    public function payment(): ?Money
    {
        return null === $this->payment ? null : Money::fromCents($this->payment);
    }

    public function months(): ?int
    {
        return $this->months;
    }

    /** Dreissig Prozent im Jahr sind kein Darlehen mehr, und Null ist eines ohne Zins. */
    private static function sane(int $rateBps): int
    {
        return max(0, min(3000, $rateBps));
    }
}
