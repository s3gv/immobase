<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Tests\Module\Tenancy\Fixture;

use App\Module\Tenancy\Domain\Tenancy;
use App\Module\Tenancy\Domain\TenancyFilter;
use App\Module\Tenancy\Domain\TenancyRepository;
use App\Shared\Ui\Page;
use App\Shared\Ui\Sort;
use DateTimeImmutable;

/**
 * Mietverhaeltnisse im Speicher.
 *
 * Fuer Tests der Anwendungsschicht, die keine Datenbank brauchen: dort geht es
 * um die Regel, nicht um das Ablegen.
 */
final class InMemoryTenancyRepository implements TenancyRepository
{
    /** @var list<Tenancy> */
    private array $tenancies = [];

    private int $next = 30001;

    public function save(Tenancy $tenancy): void
    {
        if (!\in_array($tenancy, $this->tenancies, true)) {
            $this->tenancies[] = $tenancy;
        }
    }

    public function remove(Tenancy $tenancy): void
    {
        $this->tenancies = array_values(array_filter(
            $this->tenancies,
            static fn (Tenancy $known): bool => $known !== $tenancy,
        ));
    }

    public function byId(string $id): ?Tenancy
    {
        foreach ($this->tenancies as $tenancy) {
            if ($tenancy->id() === $id) {
                return $tenancy;
            }
        }

        return null;
    }

    public function byNumber(int $number): ?Tenancy
    {
        foreach ($this->tenancies as $tenancy) {
            if ($tenancy->number() === $number) {
                return $tenancy;
            }
        }

        return null;
    }

    public function nextNumber(): int
    {
        return $this->next++;
    }

    public function byIds(array $ids): array
    {
        return array_values(array_filter(
            $this->tenancies,
            static fn (Tenancy $tenancy): bool => \in_array($tenancy->id(), $ids, true),
        ));
    }

    public function countMatching(TenancyFilter $filter): int
    {
        return \count($this->tenancies);
    }

    /**
     * Die laufenden, die bis zu diesem Tag enden.
     *
     * Hier wirklich gezaehlt und nicht pauschal beantwortet: die Tests dieser
     * Ablage stellen Mietverhaeltnisse mit Zeitraeumen auf, und eine Antwort,
     * die das ignoriert, waere keine.
     */
    public function countEndingBy(DateTimeImmutable $day): int
    {
        return \count(array_filter(
            $this->tenancies,
            static function (Tenancy $tenancy) use ($day): bool {
                $ends = $tenancy->term()->endsOn();

                return $tenancy->status()->isActive() && null !== $ends && $ends <= $day;
            },
        ));
    }

    public function matching(TenancyFilter $filter, Page $page, Sort $sort): array
    {
        return $this->tenancies;
    }

    public function activeFor(string $unitId, ?string $exceptTenancyId = null): ?Tenancy
    {
        foreach ($this->tenancies as $tenancy) {
            if ($tenancy->unitId() === $unitId && $tenancy->status()->isActive() && $tenancy->id() !== $exceptTenancyId) {
                return $tenancy;
            }
        }

        return null;
    }

    public function overlapping(
        string $unitId,
        DateTimeImmutable $from,
        ?DateTimeImmutable $to,
        ?string $exceptTenancyId = null,
    ): ?Tenancy {
        foreach ($this->tenancies as $tenancy) {
            if ($tenancy->unitId() !== $unitId || $tenancy->id() === $exceptTenancyId) {
                continue;
            }

            if (self::overlaps($tenancy, $from, $to)) {
                return $tenancy;
            }
        }

        return null;
    }

    public function forUnits(array $unitIds): array
    {
        $found = [];

        foreach ($this->tenancies as $tenancy) {
            if (\in_array($tenancy->unitId(), $unitIds, true)) {
                $found[$tenancy->unitId()][] = $tenancy;
            }
        }

        return $found;
    }

    public function rentingParties(array $partyIds): array
    {
        $found = [];

        foreach ($this->tenancies as $tenancy) {
            foreach ($tenancy->tenants() as $tenant) {
                if (\in_array($tenant->partyId(), $partyIds, true)) {
                    $found[$tenant->partyId()] = true;
                }
            }
        }

        return array_keys($found);
    }

    public function rentedBy(string $partyId): array
    {
        return [];
    }

    /** Entwuerfe zaehlen nicht — sonst liesse sich nie ein Nachmieter vorab anlegen. */
    private static function overlaps(Tenancy $tenancy, DateTimeImmutable $from, ?DateTimeImmutable $to): bool
    {
        $start = $tenancy->term()->startsOn();

        if ($tenancy->status()->isDraft() || null === $start) {
            return false;
        }

        $end = $tenancy->term()->endsOn();

        return (null === $end || $end >= $from) && (null === $to || $start <= $to);
    }
}
