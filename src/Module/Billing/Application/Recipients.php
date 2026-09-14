<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Billing\Application;

use App\Module\Billing\Domain\StatementKind;
use App\Module\Billing\Domain\StatementKinds;
use App\Module\Property\Contract\OwnerShare;
use App\Module\Property\Contract\OwnershipSpan;
use App\Module\Property\Contract\UnitBrief;
use App\Module\Property\Contract\UnitOwnership;
use App\Module\Tenancy\Contract\TenancySpan;
use DateTimeImmutable;

/**
 * Wer ein Schreiben bekommt.
 *
 * **Ein Dokument je Einheit, nicht je Eigentuemer.** Die Abrechnung gehoert
 * zur Einheit; Miteigentuemer haften gemeinsam. Alle stehen im Anschriftfeld,
 * der Betrag wird nicht geteilt.
 *
 * **Je Eigentuemerabschnitt eines**, und darin steckt dieselbe Regel wie bei
 * den Mietern: zwei Eigentuemer nacheinander sind zwei Abrechnungen, nicht
 * eine geteilte. Wer im Juli kauft, traegt nicht das halbe Jahr davor.
 *
 * Bei den Mietern ist es genauso: je Mietverhaeltnis ein Schreiben mit seinem
 * Zeitraum.
 */
final readonly class Recipients
{
    public function __construct(
        private UnitOwnership $ownership,
        private Addressed $addressed,
    ) {
    }

    /**
     * @param list<UnitBrief>                  $units
     * @param array<string, list<TenancySpan>> $spans
     *
     * @return list<array{kind: StatementKind, unit: UnitBrief, from: DateTimeImmutable, to: DateTimeImmutable, label: string, address: string, tenancy: ?TenancySpan}>
     */
    public function of(
        array $units,
        array $spans,
        StatementKinds $kinds,
        bool $isWeg,
        DateTimeImmutable $from,
        DateTimeImmutable $to,
    ): array {
        $held = $isWeg && $kinds->has(StatementKind::HouseMoney)
            ? $this->ownership->inPeriod(array_map(static fn (UnitBrief $unit): string => $unit->id, $units), $from, $to)
            : [];
        $found = [];

        foreach ($units as $unit) {
            $found = [
                ...$found,
                ...$this->forOwners($unit, $held[$unit->id] ?? []),
                ...$this->forTenants($unit, $spans[$unit->id] ?? [], $kinds),
            ];
        }

        return $found;
    }

    /**
     * @param list<OwnershipSpan> $held
     *
     * @return list<array{kind: StatementKind, unit: UnitBrief, from: DateTimeImmutable, to: DateTimeImmutable, label: string, address: string, tenancy: ?TenancySpan}>
     */
    private function forOwners(UnitBrief $unit, array $held): array
    {
        $found = [];

        foreach ($held as $span) {
            $owners = $this->addressed->of(array_map(
                static fn (OwnerShare $share): string => $share->partyId,
                $span->owners,
            ));

            if (null !== $owners) {
                $found[] = [
                    'kind' => StatementKind::HouseMoney,
                    'unit' => $unit,
                    'from' => $span->from,
                    'to' => $span->to,
                    ...$owners,
                    'tenancy' => null,
                ];
            }
        }

        return $found;
    }

    /**
     * @param list<TenancySpan> $spans
     *
     * @return list<array{kind: StatementKind, unit: UnitBrief, from: DateTimeImmutable, to: DateTimeImmutable, label: string, address: string, tenancy: ?TenancySpan}>
     */
    private function forTenants(UnitBrief $unit, array $spans, StatementKinds $kinds): array
    {
        if (!$kinds->has(StatementKind::OperatingCosts)) {
            return [];
        }

        $found = [];

        foreach ($spans as $span) {
            $tenants = $this->tenants($span);

            if (null !== $tenants) {
                $found[] = [
                    'kind' => StatementKind::OperatingCosts,
                    'unit' => $unit,
                    'from' => $span->from,
                    'to' => $span->to,
                    ...$tenants,
                    'tenancy' => $span,
                ];
            }
        }

        return $found;
    }

    /**
     * @return array{label: string, address: string}|null
     */
    private function tenants(TenancySpan $span): ?array
    {
        return $this->addressed->of($span->tenantPartyIds);
    }
}
