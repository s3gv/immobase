<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Tests\Module\Billing\Domain;

use App\Module\Billing\Domain\Taxation;
use App\Shared\Money\Money;
use PHPUnit\Framework\TestCase;

/**
 * Die Umsatzsteuer auf einer Dauermietrechnung.
 *
 * Die Zusicherung, an der alles haengt: **Netto plus Steuer ist Brutto, auf
 * den Cent.** Auf dem Blatt stehen alle drei untereinander, und der Mieter
 * rechnet nach.
 */
final class TaxationTest extends TestCase
{
    /** 19 % auf 2.300 € sind glatte 437 €. */
    public function testTheTaxIsTheRateOnTheNet(): void
    {
        self::assertSame(43700, Taxation::at(1900)->on(Money::fromCents(230000))->cents());
    }

    /** Und die Summe geht auf. */
    public function testNetAndTaxMakeGross(): void
    {
        $taxation = Taxation::at(1900);
        $net = Money::fromCents(248000);

        self::assertSame(
            $net->plus($taxation->on($net))->cents(),
            $taxation->grossOf($net)->cents(),
        );
    }

    /**
     * Ohne Ausweis gibt es keinen Steuerbetrag.
     *
     * Nicht „null Euro Steuer": wer auf einer steuerfreien Vermietung
     * Umsatzsteuer ausweist, schuldet sie nach § 14c, und ein Betrag von null
     * ist trotzdem ein Ausweis.
     */
    public function testWithoutTheOptionThereIsNoTax(): void
    {
        $exempt = Taxation::exempt();

        self::assertFalse($exempt->isCharged());
        self::assertSame(0, $exempt->on(Money::fromCents(230000))->cents());
        self::assertSame(230000, $exempt->grossOf(Money::fromCents(230000))->cents());
    }

    /** Ein Satz von null ist keine Option, sondern ein Ausweis ueber nichts. */
    public function testARateOfNothingFallsBackToExempt(): void
    {
        self::assertFalse(Taxation::at(0)->isCharged());
    }

    /** Kaufmaennisch gerundet — halbe Cent gehen nach oben. */
    public function testTheTaxIsRoundedLikeABank(): void
    {
        // 7 % auf 1,50 € sind 10,5 Cent.
        self::assertSame(11, Taxation::at(700)->on(Money::fromCents(150))->cents());
    }
}
