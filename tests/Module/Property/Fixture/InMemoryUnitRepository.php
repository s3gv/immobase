<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Tests\Module\Property\Fixture;

use App\Module\Property\Domain\Property;
use App\Module\Property\Domain\Unit;
use App\Module\Property\Domain\UnitHousehold;
use App\Module\Property\Domain\UnitOwner;
use App\Module\Property\Domain\UnitRepository;
use App\Shared\Search\SearchTerm;
use App\Shared\Ui\Page;

/**
 * Einheiten im Speicher.
 *
 * Fuer Tests der Anwendungsschicht, die keine Datenbank brauchen: dort geht es
 * um die Regel, nicht um das Ablegen.
 */
final class InMemoryUnitRepository implements UnitRepository
{
    /** @var list<Unit> */
    private array $units = [];

    public function all(int $limit): array
    {
        return \array_slice($this->units, 0, $limit);
    }

    public function pageOf(Page $page): array
    {
        return \array_slice($this->units, $page->offset(), $page->limit());
    }

    public function save(Unit $unit): void
    {
        if (!\in_array($unit, $this->units, true)) {
            $this->units[] = $unit;
        }
    }

    public function remove(Unit $unit): void
    {
        $unit->property()->remove($unit);

        $this->units = array_values(array_filter(
            $this->units,
            static fn (Unit $known): bool => $known !== $unit,
        ));
    }

    public function removeHousehold(UnitHousehold $step): void
    {
        // Im Speicher gibt es nichts zu loeschen, was nicht die Sammlung der
        // Einheit schon traegt — anders als in der Datenbank, wo die Zeile
        // fuer sich steht.
        $step->unit()->removeHousehold($step);
    }

    public function byNumber(Property $property, int $number): ?Unit
    {
        foreach ($property->units() as $unit) {
            if ($unit->number() === $number) {
                return $unit;
            }
        }

        return null;
    }

    public function ownerCount(Unit $unit): int
    {
        return \count($unit->owners());
    }

    public function byId(string $id): ?Unit
    {
        return $this->byIds([$id])[0] ?? null;
    }

    public function byIds(array $ids): array
    {
        return array_values(array_filter(
            $this->units,
            static fn (Unit $unit): bool => \in_array($unit->id(), $ids, true),
        ));
    }

    public function countManaged(): int
    {
        return \count($this->units);
    }

    /**
     * Dieselbe Suche wie {@see search()}.
     *
     * Die zentrale Suche sieht an einer Einheit auf dieselben Felder; was
     * sich unterscheidet, ist die Sortierung in der Datenbank, und die gibt
     * es hier ohnehin nicht.
     */
    public function anywhere(SearchTerm $term, int $limit): array
    {
        return $this->search($term->raw, $limit);
    }

    public function search(string $term, int $limit): array
    {
        $needle = mb_strtolower($term);

        $found = array_filter(
            $this->units,
            static fn (Unit $unit): bool => str_contains(mb_strtolower($unit->label()), $needle)
                || str_contains(mb_strtolower($unit->property()->name()), $needle)
                || (string) $unit->property()->number() === $term,
        );

        return \array_slice(array_values($found), 0, $limit);
    }

    public function ownedBy(string $partyId): array
    {
        return array_values(array_filter(
            $this->units,
            static fn (Unit $unit): bool => [] !== array_filter(
                $unit->owners(),
                static fn (UnitOwner $owner): bool => $owner->partyId() === $partyId,
            ),
        ));
    }
}
