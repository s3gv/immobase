<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Plugin\UserInterface\Background;

use App\Module\Plugin\Application\DeliverEvents;
use App\Module\Plugin\Application\SweepDeliveries;
use App\Shared\Background\RunsInBackground;

/**
 * Faellige Webhooks zustellen, aufgegebene wegraeumen.
 *
 * Aus dem Anwendungscontainer, weil die Plugins an dessen 127.0.0.1 lauschen.
 * Das Wegraeumen ist eine Abfrage und laeuft gleich mit.
 */
final readonly class DeliverEventsInBackground implements RunsInBackground
{
    public function __construct(
        private DeliverEvents $deliver,
        private SweepDeliveries $sweep,
    ) {
    }

    public function name(): string
    {
        return 'webhooks';
    }

    public function everySeconds(): int
    {
        return 5;
    }

    public function run(): void
    {
        ($this->deliver)();
        ($this->sweep)();
    }
}
