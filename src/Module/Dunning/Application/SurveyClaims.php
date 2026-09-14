<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Dunning\Application;

use App\Module\Dunning\Domain\Claim;
use App\Module\Dunning\Domain\Notice;
use App\Module\Dunning\Domain\NoticeRepository;
use DateTimeImmutable;

/**
 * Der Zustand der Vorgaenge — gerechnet, nicht gespeichert.
 *
 * „Frist abgelaufen" haengt daran, welches Schreiben zuletzt hinausging und
 * welche Frist darauf stand. Beides steht nicht in der Forderung, und ein
 * gespeicherter Zustand waere am Tag nach dem Speichern falsch.
 */
final readonly class SurveyClaims
{
    public function __construct(
        private NoticeRepository $notices,
        private TheInterest $interest,
    ) {
    }

    /**
     * @param list<Claim> $claims
     *
     * @return list<ClaimState>
     */
    public function states(array $claims, DateTimeImmutable $on): array
    {
        $notices = $this->latestFor($claims);

        return array_map(fn (Claim $claim): ClaimState => $this->state($claim, $notices[$claim->id()] ?? null, $on), $claims);
    }

    /** Der Zustand einer einzelnen Forderung — fuer die Detailseite. */
    public function of(Claim $claim, DateTimeImmutable $on): ClaimState
    {
        return $this->state($claim, $this->latestFor([$claim])[$claim->id()] ?? null, $on);
    }

    public function state(Claim $claim, ?Notice $latest, DateTimeImmutable $on): ClaimState
    {
        $expired = null !== $latest && $latest->payBy() < $on;
        $last = $latest?->level();
        $wasTheLastLevel = true === $last?->isLast();
        $running = $claim->arrears()->isOpen();

        return new ClaimState(
            claim: $claim,
            open: $claim->open(),
            interest: $this->interest->of($claim, $claim->arrears()->settledOn() ?? $on)->total(),
            level: $last,
            payBy: $latest?->payBy(),
            daysOverdue: $claim->arrears()->daysOn($on),
            daysLeft: self::daysLeft($latest?->payBy(), $on),
            isDue: $running && (null === $latest || ($expired && !$wasTheLastLevel)),
            needsTheCourt: $running && $expired && $wasTheLastLevel,
        );
    }

    /** Negativ heisst abgelaufen; null heisst, es laeuft gar keine Frist. */
    private static function daysLeft(?DateTimeImmutable $payBy, DateTimeImmutable $on): ?int
    {
        if (null === $payBy) {
            return null;
        }

        $days = (int) $on->diff($payBy)->days;

        return $payBy < $on ? -$days : $days;
    }

    /**
     * Das juengste ausgestellte Schreiben je Forderung.
     *
     * Eine Abfrage fuer alle: bei zweihundert Vorgaengen waeren es sonst
     * zweihundert.
     *
     * @param list<Claim> $claims
     *
     * @return array<string, Notice>
     */
    private function latestFor(array $claims): array
    {
        $ids = array_map(static fn (Claim $claim): string => $claim->id(), $claims);
        $found = [];

        // Das juengste zuerst — was danach kommt, ueberschreibt nichts mehr.
        foreach ($this->notices->forClaims($ids) as $notice) {
            if ($notice->isDraft()) {
                continue;
            }

            foreach ($notice->lines() as $line) {
                $found[$line->claimId()] ??= $notice;
            }
        }

        return $found;
    }
}
