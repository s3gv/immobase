<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Shared\Background;

use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;
use Throwable;

/**
 * Ein Durchgang durch alle Hintergrundarbeiten.
 *
 * Jede laeuft, wenn ihr Abstand um ist — die Plugins alle paar Sekunden, das
 * Aufraeumen einmal je Minute. **Ein Fehler in der einen haelt die anderen
 * nicht auf:** ein Mailserver, der nicht antwortet, soll keine Webhooks
 * verzoegern und kein Plugin am Neustart hindern.
 */
final class BackgroundRound
{
    /** @var array<string, int> wann jede Arbeit zuletzt lief */
    private array $lastRun = [];

    /**
     * @param iterable<RunsInBackground> $jobs
     */
    public function __construct(
        #[AutowireIterator('background.job')]
        private readonly iterable $jobs,
        private readonly ClockInterface $clock,
    ) {
    }

    /**
     * @return list<string> Meldungen ueber Fehler, sonst nichts
     */
    public function __invoke(): array
    {
        $failures = [];

        foreach ($this->jobs as $job) {
            if (!$this->isDue($job)) {
                continue;
            }

            $this->lastRun[$job->name()] = $this->clock->now()->getTimestamp();

            try {
                $job->run();
            } catch (Throwable $failed) {
                $failures[] = $job->name().': '.$failed->getMessage();
            }
        }

        return $failures;
    }

    private function isDue(RunsInBackground $job): bool
    {
        $last = $this->lastRun[$job->name()] ?? null;

        return null === $last || $this->clock->now()->getTimestamp() - $last >= $job->everySeconds();
    }
}
