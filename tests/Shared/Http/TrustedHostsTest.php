<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Tests\Shared\Http;

use App\Shared\Http\TrustedHosts;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\Exception\RuntimeException;

final class TrustedHostsTest extends TestCase
{
    public function testTheNameComesFromTheAddressAndIsAnchored(): void
    {
        $hosts = (new TrustedHosts())->getEnv('trusted_hosts', 'DEFAULT_URI', static fn (): string => 'https://Immobase.Example.org:8443/');

        self::assertSame('^immobase\.example\.org$,^localhost$,^127\.0\.0\.1$,^\[\:\:1\]$', $hosts);
    }

    public function testAnAddressWithoutHostStopsTheStart(): void
    {
        $this->expectException(RuntimeException::class);

        (new TrustedHosts())->getEnv('trusted_hosts', 'DEFAULT_URI', static fn (): string => 'kein-link');
    }
}
