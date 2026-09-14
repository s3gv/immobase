<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Property\Infrastructure;

use App\Module\Property\Domain\Property;
use App\Module\Property\Domain\PropertyStatus;
use App\Module\Property\Domain\Unit;
use App\Module\Property\Domain\UnitHousehold;
use App\Module\Property\Domain\UnitRepository;
use App\Module\Property\Domain\UnitStatus;
use App\Shared\Search\LikePattern;
use App\Shared\Search\SearchTerm;
use App\Shared\Ui\Page;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;

final readonly class DoctrineUnitRepository implements UnitRepository
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function save(Unit $unit): void
    {
        $this->entityManager->persist($unit);
        $this->entityManager->flush();
    }

    public function remove(Unit $unit): void
    {
        // Aus der Sammlung nehmen und nicht nur aus der Datenbank: sonst
        // haelt das geladene Objekt die Einheit weiter, und die naechste
        // Nummer waere schon vergeben.
        $unit->property()->remove($unit);

        $this->entityManager->remove($unit);
        $this->entityManager->flush();
    }

    public function removeHousehold(UnitHousehold $step): void
    {
        // Aus der Sammlung nehmen und nicht nur aus der Datenbank: sonst
        // haelt die geladene Einheit den Eintrag weiter, und der naechste
        // Blick auf die Staffel zeigt ihn noch.
        $step->unit()->removeHousehold($step);

        $this->entityManager->remove($step);
        $this->entityManager->flush();
    }

    public function byNumber(Property $property, int $number): ?Unit
    {
        return $this->entityManager->getRepository(Unit::class)
            ->findOneBy(['property' => $property, 'number' => $number]);
    }

    public function ownerCount(Unit $unit): int
    {
        return \count($unit->owners());
    }

    public function ownedBy(string $partyId): array
    {
        /** @var list<Unit> $units */
        $units = $this->entityManager->createQueryBuilder()
            ->select('u', 'p', 'o')
            ->from(Unit::class, 'u')
            ->join('u.property', 'p')
            ->join('u.owners', 'o')
            ->where('o.partyId = :party')
            ->setParameter('party', $partyId)
            ->orderBy('p.number', 'ASC')
            ->addOrderBy('u.number', 'ASC')
            ->getQuery()
            ->getResult();

        return $units;
    }

    public function byId(string $id): ?Unit
    {
        return $this->entityManager->getRepository(Unit::class)->find($id);
    }

    public function byIds(array $ids): array
    {
        if ([] === $ids) {
            return [];
        }

        /** @var list<Unit> $units */
        $units = $this->entityManager->createQueryBuilder()
            ->select('u', 'p')
            ->from(Unit::class, 'u')
            ->join('u.property', 'p')
            ->where('u.id IN (:ids)')
            ->setParameter('ids', $ids)
            ->getQuery()
            ->getResult();

        return $units;
    }

    public function pageOf(Page $page): array
    {
        $query = $this->entityManager->createQueryBuilder()
            ->select('u', 'p')
            ->from(Unit::class, 'u')
            ->join('u.property', 'p')
            ->orderBy('p.number', 'ASC')
            ->addOrderBy('u.number', 'ASC')
            ->setFirstResult($page->offset())
            ->setMaxResults($page->limit());

        self::onlyManaged($query);

        /** @var list<Unit> $units */
        $units = $query->getQuery()->getResult();

        return $units;
    }

    public function all(int $limit): array
    {
        $query = $this->entityManager->createQueryBuilder()
            ->select('u', 'p')
            ->from(Unit::class, 'u')
            ->join('u.property', 'p')
            ->orderBy('p.number', 'ASC')
            ->addOrderBy('u.number', 'ASC')
            ->setMaxResults($limit);

        self::onlyManaged($query);

        /** @var list<Unit> $units */
        $units = $query->getQuery()->getResult();

        return $units;
    }

    public function countManaged(): int
    {
        $query = $this->entityManager->createQueryBuilder()
            ->select('COUNT(u.id)')
            ->from(Unit::class, 'u')
            ->join('u.property', 'p');

        self::onlyManaged($query);

        $count = $query->getQuery()->getSingleScalarResult();

        return is_numeric($count) ? (int) $count : 0;
    }

    public function anywhere(SearchTerm $term, int $limit): array
    {
        /** @var list<Unit> $units */
        $units = SearchedUnits::of($this->entityManager, $term, $limit)->getQuery()->getResult();

        return $units;
    }

    public function search(string $term, int $limit): array
    {
        $query = $this->entityManager->createQueryBuilder()
            ->select('u', 'p')
            ->from(Unit::class, 'u')
            ->join('u.property', 'p')
            ->orderBy('p.number', 'ASC')
            ->addOrderBy('u.number', 'ASC')
            ->setMaxResults($limit);

        self::onlyManaged($query);

        $conditions = ['LOWER(u.label) LIKE :needle', 'LOWER(p.name) LIKE :needle'];
        $query->setParameter('needle', LikePattern::containing(mb_strtolower($term)));

        // Wer eine Nummer im Kopf hat, tippt sie ein — dieselbe Ueberlegung
        // wie bei den Stammdaten, und derselbe genaue Vergleich: „200" soll
        // nicht jedes Objekt treffen, dessen Nummer eine 200 enthaelt.
        if (ctype_digit($term)) {
            $conditions[] = 'p.number = :number';
            $query->setParameter('number', (int) $term);
        }

        $query->andWhere(implode(' OR ', $conditions));

        /** @var list<Unit> $units */
        $units = $query->getQuery()->getResult();

        return $units;
    }

    /**
     * Abgegebene Einheiten stehen nicht zur Wahl.
     *
     * Ein neues Mietverhaeltnis in einer Einheit, die wir nicht mehr
     * verwalten, ist keine Eingabe, die jemand machen will — und ein
     * abgewickeltes Objekt nimmt seine Einheiten mit aus der Auswahl.
     */
    private static function onlyManaged(QueryBuilder $query): void
    {
        $query
            ->andWhere('u.management.status = :active')
            ->andWhere('p.management.status <> :ended')
            ->setParameter('active', UnitStatus::Active->value)
            ->setParameter('ended', PropertyStatus::Ended->value);
    }
}
