<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Tests\Module\Tenancy\Domain;

use App\Module\Tenancy\Domain\Deposit;
use App\Module\Tenancy\Domain\DepositKind;
use App\Shared\Money\Money;
use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * Ohne Betrag gibt es keine Kaution.
 */
final class DepositTest extends TestCase
{
    /**
     * Art und Eingangsdatum allein wuerden eine Kaution behaupten, die
     * niemand beziffern kann — und das faellt beim Auszug auf, wenn niemand
     * mehr weiss, wie viel es war.
     */
    public function testFormAndDateAloneAreNotADeposit(): void
    {
        $deposit = Deposit::of(null, DepositKind::Cash, new DateTimeImmutable('2026-03-20'));

        self::assertFalse($deposit->isAgreed());
        self::assertNull($deposit->amount());
        self::assertNull($deposit->kind());
        self::assertNull($deposit->receivedOn());
    }

    /** Null Euro sind auch keine Kaution. */
    public function testZeroIsNoDepositEither(): void
    {
        self::assertFalse(Deposit::of(Money::zero(), DepositKind::Cash, null)->isAgreed());
    }

    public function testWithAnAmountItIsOne(): void
    {
        $deposit = Deposit::of(Money::fromCents(195000), DepositKind::Guarantee, null);

        self::assertTrue($deposit->isAgreed());
        self::assertSame(195000, $deposit->amount()?->cents());
        self::assertSame(DepositKind::Guarantee, $deposit->kind());
    }

    /**
     * Und keine negative.
     *
     * MoneyInput laesst ein Vorzeichen zu, weil eine Buchung negativ sein
     * kann. Eine hinterlegte Sicherheit nicht — „minus 1.200 Euro Kaution"
     * stuende beim Auszug als Forderung da.
     */
    public function testANegativeDepositIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Deposit::of(Money::fromCents(-120000), DepositKind::Cash, null);
    }

    /** Vereinbart, aber noch nicht da — der Fall, der auffallen soll. */
    public function testAnAgreedDepositWithoutAReceiptIsOutstanding(): void
    {
        self::assertTrue(Deposit::of(Money::fromCents(195000), DepositKind::Cash, null)->isOutstanding());
        self::assertFalse(
            Deposit::of(Money::fromCents(195000), DepositKind::Cash, new DateTimeImmutable('2026-03-20'))
                ->isOutstanding(),
        );
        self::assertFalse(Deposit::none()->isOutstanding(), 'Keine Kaution steht auch nicht aus');
    }
}
