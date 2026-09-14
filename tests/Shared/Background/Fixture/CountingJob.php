<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Tests\Shared\Background\Fixture;

use App\Shared\Background\RunsInBackground;
use RuntimeException;

/** Eine Hintergrundarbeit, die zaehlt, wie oft sie lief — und auf Wunsch scheitert. */
final class CountingJob implements RunsInBackground
{
    public int $runs = 0;

    public function __construct(
        private readonly string $name,
        private readonly int $seconds,
        private readonly bool $fails = false,
    ) {
    }

    public function name(): string
    {
        return $this->name;
    }

    public function everySeconds(): int
    {
        return $this->seconds;
    }

    public function run(): void
    {
        ++$this->runs;

        if ($this->fails) {
            throw new RuntimeException('Mailserver antwortet nicht');
        }
    }
}
