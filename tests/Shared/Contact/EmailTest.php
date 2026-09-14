<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Tests\Shared\Contact;

use App\Shared\Contact\Email;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class EmailTest extends TestCase
{
    public function testAcceptsValidAddress(): void
    {
        self::assertSame('erika@example.org', Email::fromString('erika@example.org')->toString());
    }

    public function testNormalisesToLowercase(): void
    {
        self::assertSame('erika@example.org', Email::fromString('Erika@Example.ORG')->toString());
    }

    public function testTrimsSurroundingWhitespace(): void
    {
        self::assertSame('erika@example.org', Email::fromString('  erika@example.org  ')->toString());
    }

    public function testRejectsAddressWithoutAtSign(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Email::fromString('not-an-address');
    }

    public function testRejectsEmptyString(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Email::fromString('');
    }

    public function testComparesNormalised(): void
    {
        self::assertTrue(Email::fromString('A@b.de')->equals(Email::fromString('a@B.de')));
    }
}
