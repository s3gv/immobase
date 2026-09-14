<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Tests\Functional;

use Psr\Cache\CacheItemPoolInterface;

/**
 * Raeumt die Ratenbegrenzungen zwischen Tests weg.
 *
 * Sie liegen im Dateisystem-Cache und ueberleben damit den Testlauf. Ohne
 * dieses Aufraeumen traegt ein Test, der die Bremse ausloest, seinen Zustand
 * zum naechsten — und der scheitert dann an etwas, das mit ihm nichts zu tun
 * hat. Solche Tests sind schlimmer als keine.
 */
trait ForgetsRateLimits
{
    protected static function forgetRateLimits(): void
    {
        if (null === self::$kernel) {
            return;
        }

        $pool = self::getContainer()->get('cache.rate_limiter');

        if ($pool instanceof CacheItemPoolInterface) {
            $pool->clear();
        }
    }
}
