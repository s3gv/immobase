<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Plugin\Application;

use App\Module\Plugin\Domain\DeliveryRepository;
use Symfony\Component\Clock\ClockInterface;

/**
 * Aufgegebene Zustellungen verfallen nach einer Woche.
 *
 * Sie bleiben so lange stehen, damit jemand nachsehen kann, warum sie nicht
 * ankamen — und danach gehen sie. Eine Ablage, die nur waechst, weil ein
 * Plugin seit Monaten aus ist, waere das Gegenteil von „ein Plugin traegt
 * nicht".
 */
final readonly class SweepDeliveries
{
    private const string KEEP = '-7 days';

    public function __construct(
        private DeliveryRepository $deliveries,
        private ClockInterface $clock,
    ) {
    }

    public function __invoke(): int
    {
        return $this->deliveries->sweep($this->clock->now()->modify(self::KEEP));
    }
}
