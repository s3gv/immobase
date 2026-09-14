<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Tests\Module\Plugin\Fixture;

use App\Module\Plugin\Domain\DeliveryRepository;
use App\Module\Plugin\Domain\TileRepository;
use DateTimeImmutable;

/** Ablagen fuer Zustellungen und Kacheln, die nichts halten. */
final class NoDeliveries implements DeliveryRepository, TileRepository
{
    public function append(array $deliveries): void
    {
    }

    public function due(DateTimeImmutable $now, int $limit): array
    {
        return [];
    }

    public function forget(string $id): void
    {
    }

    public function retryLater(string $id, DateTimeImmutable $at, string $error): void
    {
    }

    public function giveUp(string $id, string $error): void
    {
    }

    public function forgetPlugin(string $plugin): void
    {
    }

    public function sweep(DateTimeImmutable $before): int
    {
        return 0;
    }

    public function replace(string $plugin, array $tiles): void
    {
    }

    public function fresh(DateTimeImmutable $since): array
    {
        return [];
    }
}
