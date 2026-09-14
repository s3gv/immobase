<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Tests\Module\Tenancy\Contract;

use App\Module\Tenancy\Contract\UnitAdvance;
use App\Shared\Money\Money;
use PHPUnit\Framework\TestCase;

/** Was eine Einheit an Nebenkosten zahlt — mit Umsatzsteuer vermietet brutto. */
final class UnitAdvanceTest extends TestCase
{
    public function testWithoutVatItIsTheContractAmount(): void
    {
        self::assertSame(50000, self::advance(0)->total()->cents());
    }

    public function testWithVatItIsWhatArrives(): void
    {
        self::assertSame(59500, self::advance(1900)->total()->cents(), '350 + 150 netto, dazu 19 %');
    }

    private static function advance(int $rateBps): UnitAdvance
    {
        return new UnitAdvance('einheit', 30006, Money::fromCents(35000), Money::fromCents(15000), '/miete/30006', $rateBps);
    }
}
