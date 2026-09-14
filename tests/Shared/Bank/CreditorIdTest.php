<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Tests\Shared\Bank;

use App\Shared\Bank\CreditorId;
use App\Shared\Bank\NotACreditorId;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Die Glaeubiger-ID — gepruefte Gestalt, gepruefte Ziffern.
 *
 * Ein Zahlendreher faellt sonst erst auf, wenn die Bank den Einzug
 * zurueckgibt.
 */
final class CreditorIdTest extends TestCase
{
    public function testTheBundesbankExampleIsValid(): void
    {
        self::assertSame('DE98ZZZ09999999999', CreditorId::fromString('de98 zzz0 9999 9999 99')->toString());
    }

    public function testTheBusinessCodeDoesNotCountForTheCheck(): void
    {
        self::assertSame('DE98ABC09999999999', CreditorId::fromString('DE98ABC09999999999')->toString());
    }

    #[DataProvider('broken')]
    public function testBrokenOnesAreRefused(string $value): void
    {
        $this->expectException(NotACreditorId::class);

        CreditorId::fromString($value);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function broken(): iterable
    {
        yield 'Zahlendreher' => ['DE98ZZZ09999999998'];
        yield 'falsche Prüfziffer' => ['DE97ZZZ09999999999'];
        yield 'keine Gestalt' => ['ZZZ09999999999'];
        yield 'eine IBAN' => ['DE02120300000000202051x'];
    }

    public function testEmptyIsNotGiven(): void
    {
        self::assertNull(CreditorId::orNull('  '));
    }
}
