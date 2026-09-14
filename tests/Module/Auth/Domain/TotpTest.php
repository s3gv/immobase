<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Tests\Module\Auth\Domain;

use App\Module\Auth\Domain\Totp;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * TOTP gegen die Testvektoren aus RFC 6238, Anhang B.
 *
 * Das ist der Grund, warum sich das Verfahren selbst schreiben laesst: es gibt
 * eine amtliche Antwort. Ohne diesen Test waere die Umsetzung eine Behauptung.
 *
 * Der RFC nennt achtstellige Codes; unsere sind sechsstellig, also die letzten
 * sechs Stellen derselben Zahl.
 */
final class TotpTest extends TestCase
{
    /** "12345678901234567890" in Base32 — das Geheimnis des RFC. */
    private const string SECRET = 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ';

    /**
     * @return iterable<string, array{int, string}>
     */
    public static function vectors(): iterable
    {
        yield '1970-01-01 00:00:59' => [59, '287082'];
        yield '2005-03-18 01:58:29' => [1111111109, '081804'];
        yield '2005-03-18 01:58:31' => [1111111111, '050471'];
        yield '2009-02-13 23:31:30' => [1234567890, '005924'];
        yield '2033-05-18 03:33:20' => [2000000000, '279037'];
    }

    #[DataProvider('vectors')]
    public function testMatchesTheReferenceImplementation(int $timestamp, string $expected): void
    {
        self::assertSame($expected, Totp::codeForStep(self::SECRET, Totp::step($timestamp)));
    }

    /**
     * Uhren laufen auseinander, und niemand tippt in Nullzeit — ein Fenster
     * davor und danach wird akzeptiert. Mehr nicht: jedes weitere verlaengert
     * die Zeit, in der ein abgefangener Code noch gilt.
     */
    public function testAcceptsExactlyOneWindowInEachDirection(): void
    {
        $now = 1111111109;
        $step = Totp::step($now);

        foreach ([-1, 0, 1] as $offset) {
            $code = Totp::codeForStep(self::SECRET, $step + $offset);

            self::assertSame(
                $step + $offset,
                Totp::matchingStep(self::SECRET, $code, $now),
                \sprintf('Fenster %+d gehört dazu', $offset),
            );
        }

        foreach ([-2, 2] as $offset) {
            $code = Totp::codeForStep(self::SECRET, $step + $offset);

            self::assertNull(
                Totp::matchingStep(self::SECRET, $code, $now),
                \sprintf('Fenster %+d liegt zu weit weg', $offset),
            );
        }
    }

    public function testRejectsAWrongCode(): void
    {
        self::assertNull(Totp::matchingStep(self::SECRET, '000000', 1111111109));
    }

    public function testNewSecretsAreDistinctAndUsable(): void
    {
        $first = Totp::newSecret();
        $second = Totp::newSecret();

        self::assertNotSame($first, $second);
        self::assertSame(32, \strlen($first), '160 Bit in Base32');
        self::assertMatchesRegularExpression('/^[A-Z2-7]+$/', $first);
        self::assertNotNull(Totp::matchingStep($first, Totp::codeForStep($first, Totp::step(1234567890)), 1234567890));
    }

    /**
     * Die Adresse, die in der App landet. Der Aussteller steht zweimal darin —
     * einmal im Pfad, einmal als Parameter; Apps lesen mal das eine, mal das
     * andere.
     */
    public function testBuildsAnOtpauthUri(): void
    {
        $uri = Totp::uri(self::SECRET, 'erika@example.org', 'ImmoBase');

        self::assertStringStartsWith('otpauth://totp/ImmoBase:erika%40example.org?', $uri);
        self::assertStringContainsString('secret='.self::SECRET, $uri);
        self::assertStringContainsString('issuer=ImmoBase', $uri);
        self::assertStringContainsString('period=30', $uri);
    }
}
