<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Tenancy\Application;

use App\Module\Tenancy\Contract\AdvanceStep;
use App\Module\Tenancy\Contract\PersonStep;
use App\Module\Tenancy\Contract\TenancySpan;
use App\Module\Tenancy\Contract\TenancySpans;
use App\Module\Tenancy\Domain\HouseholdStep;
use App\Module\Tenancy\Domain\RentStep;
use App\Module\Tenancy\Domain\Tenancy;
use App\Module\Tenancy\Domain\TenancyRepository;
use App\Module\Tenancy\Domain\TenancyStatus;
use App\Module\Tenancy\Domain\Tenant;
use App\Shared\Time\Windows;
use DateTimeImmutable;

/**
 * Mietverhaeltnisse als Zeitabschnitte, fuer die Abrechnung.
 *
 * Die Arbeit steckt im Beschneiden: eine Staffel sagt nur, ab wann etwas
 * gilt. Wie lange es gilt, ergibt sich aus der naechsten Stufe — und am Rand
 * aus dem Abrechnungszeitraum. Wer das jedes Mal neu ausrechnet, rechnet es
 * frueher oder spaeter einmal anders.
 */
final readonly class LookupTenancySpans implements TenancySpans
{
    public function __construct(private TenancyRepository $tenancies)
    {
    }

    public function inPeriod(array $unitIds, DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        $spans = [];

        foreach ($this->tenancies->forUnits($unitIds) as $unitId => $tenancies) {
            $found = [];

            foreach ($tenancies as $tenancy) {
                $span = self::spanOf($tenancy, $from, $to);

                if (null !== $span) {
                    $found[] = $span;
                }
            }

            if ([] !== $found) {
                usort($found, static fn (TenancySpan $one, TenancySpan $other): int => $one->from <=> $other->from);
                $spans[$unitId] = $found;
            }
        }

        return $spans;
    }

    private static function spanOf(Tenancy $tenancy, DateTimeImmutable $from, DateTimeImmutable $to): ?TenancySpan
    {
        if (TenancyStatus::Draft === $tenancy->status()) {
            return null;
        }

        $term = $tenancy->term();
        $begins = Windows::later($term->startsOn() ?? $from, $from);
        $ends = Windows::earlier($term->endsOn() ?? $to, $to);

        if ($begins > $ends) {
            return null;
        }

        return new TenancySpan(
            tenancyId: $tenancy->id(),
            number: $tenancy->number(),
            unitId: $tenancy->unitId(),
            from: $begins,
            to: $ends,
            tenantPartyIds: array_values(array_map(
                static fn (Tenant $tenant): string => $tenant->partyId(),
                $tenancy->tenants(),
            )),
            advances: self::advances($tenancy, $begins, $ends),
            persons: self::persons($tenancy, $begins, $ends),
            vatCharged: $tenancy->taxation()->isCharged(),
            vatRateBps: $tenancy->taxation()->rateBps(),
        );
    }

    /** @return list<AdvanceStep> */
    private static function advances(Tenancy $tenancy, DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        return array_map(
            static fn (array $window): AdvanceStep => new AdvanceStep(
                $window['from'],
                $window['to'],
                $window['step']->rent()->operatingCosts,
                $window['step']->rent()->heating,
            ),
            Windows::within(
                $tenancy->schedule()->steps(),
                static fn (RentStep $step): DateTimeImmutable => $step->startsOn(),
                $from,
                $to,
            ),
        );
    }

    /** @return list<PersonStep> */
    private static function persons(Tenancy $tenancy, DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        return array_map(
            static fn (array $window): PersonStep => new PersonStep(
                $window['from'],
                $window['to'],
                $window['step']->people(),
            ),
            Windows::within(
                $tenancy->household()->steps(),
                static fn (HouseholdStep $step): DateTimeImmutable => $step->startsOn(),
                $from,
                $to,
            ),
        );
    }
}
