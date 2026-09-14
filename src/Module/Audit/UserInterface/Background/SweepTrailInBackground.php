<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Audit\UserInterface\Background;

use App\Module\Audit\Application\SweepTheTrail;
use App\Shared\Background\RunsInBackground;

/** Protokollzeilen, die aelter als achtundvierzig Stunden sind, gehen. */
final readonly class SweepTrailInBackground implements RunsInBackground
{
    public function __construct(private SweepTheTrail $sweep)
    {
    }

    public function name(): string
    {
        return 'audit';
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
