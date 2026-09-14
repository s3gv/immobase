<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Tests\Functional;

use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionProperty;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\RateLimiter\RateLimiterFactory;

/**
 * Jede Bremse sperrt, bevor sie zaehlt.
 *
 * Ohne Sperre lesen gleichzeitige Anfragen denselben Zaehlerstand, und aus
 * fuenf erlaubten Versuchen werden so viele, wie parallel ankommen.
 */
final class RateLimiterLockTest extends KernelTestCase
{
    /** @return iterable<string, array{string}> */
    public static function limiters(): iterable
    {
        foreach (['_login_local_main', '_login_global_main', 'password_reset', 'password_reset_ip', 'second_factor', 'second_factor_mail'] as $name) {
            yield $name => ['limiter.'.$name];
        }
    }

    #[DataProvider('limiters')]
    public function testTheLimiterCountsUnderALock(string $service): void
    {
        self::bootKernel();
        $factory = self::getContainer()->get($service);
        self::assertInstanceOf(RateLimiterFactory::class, $factory);

        self::assertInstanceOf(LockFactory::class, (new ReflectionProperty(RateLimiterFactory::class, 'lockFactory'))->getValue($factory));
    }
}
