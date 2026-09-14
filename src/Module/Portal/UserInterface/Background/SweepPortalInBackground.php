<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Portal\UserInterface\Background;

use App\Module\Portal\Application\SweepThePortal;
use App\Shared\Background\RunsInBackground;

/**
 * Abgelaufene Anhaenge loeschen, faellige Benachrichtigungen verschicken.
 *
 * Einmal je Minute: die Frist einer Datei zaehlt in Tagen, die
 * Benachrichtigung in Minuten. Haeufiger nachzusehen bringt nichts, seltener
 * liesse eine Mail um Viertelstunden altern.
 */
final readonly class SweepPortalInBackground implements RunsInBackground
{
    public function __construct(private SweepThePortal $sweep)
    {
    }

    public function name(): string
    {
        return 'portal';
    }

    public function everySeconds(): int
    {
        return 60;
    }

    public function run(): void
    {
        ($this->sweep)();
    }
}
