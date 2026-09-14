<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Finance\Infrastructure;

use App\Module\Finance\Domain\CostItem;
use App\Module\Finance\Domain\CostItemFilter;
use App\Module\Finance\Domain\CostItemRepository;
use App\Shared\Search\LikePattern;
use App\Shared\Ui\Page;
use App\Shared\Ui\Sort;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;
use RuntimeException;

final readonly class DoctrineCostItemRepository implements CostItemRepository
{
    /** Was sich sortieren laesst — und wie es in der Abfrage heisst. */
    public const array SORTABLE = [
        'nummer' => 'c.number',
        'kostenart' => 'k.name',
        'faelligkeit' => 'c.due.interval',
    ];

    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function save(CostItem $item): void
    {
        $this->entityManager->persist($item);
        $this->entityManager->flush();
    }

    public function remove(CostItem $item): void
    {
        $this->entityManager->remove($item);
        $this->entityManager->flush();
    }

    public function byId(string $id): ?CostItem
    {
        return $this->entityManager->getRepository(CostItem::class)->find($id);
    }

    public function byNumber(int $number): ?CostItem
    {
        return $this->entityManager->getRepository(CostItem::class)->findOneBy(['number' => $number]);
    }

    public function nextNumber(): int
    {
        $next = $this->entityManager->getConnection()->fetchOne("SELECT nextval('finance_cost_item_number_seq')");

        if (!is_numeric($next)) {
            throw new RuntimeException('Die Nummernfolge der Kostenpositionen antwortet nicht.');
        }

        return (int) $next;
    }

    public function countMatching(CostItemFilter $filter): int
    {
        $count = $this->restricted($filter, 'COUNT(c.id)')->getQuery()->getSingleScalarResult();

        return is_numeric($count) ? (int) $count : 0;
    }

    public function matching(CostItemFilter $filter, Page $page, Sort $sort): array
    {
        /** @var list<CostItem> $items */
        $items = $this->restricted($filter, 'c')
            ->orderBy(self::SORTABLE[$sort->field] ?? 'c.number', $sort->sql())
            ->addOrderBy('c.number', 'ASC')
            ->setFirstResult($page->offset())
            ->setMaxResults($page->limit())
            ->getQuery()
            ->getResult();

        return $items;
    }

    public function countsFor(array $propertyIds): array
    {
        if ([] === $propertyIds) {
            return [];
        }

        /** @var list<array{propertyId: string, tally: int}> $rows */
        $rows = $this->entityManager->createQueryBuilder()
            ->select('c.propertyId AS propertyId', 'COUNT(c.id) AS tally')
            ->from(CostItem::class, 'c')
            ->where('c.propertyId IN (:properties)')
            ->setParameter('properties', $propertyIds, ArrayParameterType::STRING)
            ->groupBy('c.propertyId')
            ->getQuery()
            ->getResult();

        return array_map(intval(...), array_column($rows, 'tally', 'propertyId'));
    }

    public function all(): array
    {
        return $this->itemsWhere(null, '');
    }

    public function forProperty(string $propertyId): array
    {
        return $this->itemsWhere('propertyId', $propertyId);
    }

    public function forMeasure(string $reference): array
    {
        return '' === $reference ? [] : $this->itemsWhere('measure.reference', $reference);
    }

    public function anyUsing(string $keyId): bool
    {
        $count = $this->entityManager->createQueryBuilder()
            ->select('COUNT(c.id)')
            ->from(CostItem::class, 'c')
            ->where('c.key = :key')
            ->setParameter('key', $keyId)
            ->getQuery()
            ->getSingleScalarResult();

        return is_numeric($count) && $count > 0;
    }

    /**
     * Die Positionen samt Jahren und Mengen — ohne Feld: alle. Mitgeladen,
     * weil jeder Aufrufer beides anfasst. Das Feld benennt eine Spalte und
     * kommt aus dem Aufruf, nie von aussen.
     *
     * @return list<CostItem>
     */
    private function itemsWhere(?string $field, string $value): array
    {
        $query = $this->entityManager->createQueryBuilder()
            ->select('c', 'y', 'u')
            ->from(CostItem::class, 'c')
            ->leftJoin('c.years', 'y')
            ->leftJoin('y.units', 'u')
            ->orderBy('c.number', 'ASC');

        if (null !== $field) {
            $query->where('c.'.$field.' = :value')->setParameter('value', $value);
        }

        /** @var list<CostItem> $items */
        $items = $query->getQuery()->getResult();

        return $items;
    }

    private function restricted(CostItemFilter $filter, string $select): QueryBuilder
    {
        $query = $this->entityManager->createQueryBuilder()
            ->select($select)
            ->from(CostItem::class, 'c')
            ->join('c.kind', 'k');

        if (null !== $filter->propertyId) {
            $query->andWhere('c.propertyId = :property')->setParameter('property', $filter->propertyId);
        }

        if (null !== $filter->kindId) {
            $query->andWhere('k.id = :kind')->setParameter('kind', $filter->kindId);
        }

        if (null !== $filter->apportionable) {
            self::restrictToApportionable($query, $filter->apportionable);
        }

        if (null !== $filter->search) {
            self::restrictToSearch($query, $filter);
        }

        if (!$filter->withPast) {
            // Beendete stehen bereit, aber nicht im Weg.
            $query->andWhere('c.endsOn IS NULL');
        }

        return $query;
    }

    /**
     * Umlagefaehig ist, was die Position sagt — und sonst, was die Kostenart
     * sagt. Genau die Regel aus CostItem::isApportionable(), nur in SQL.
     *
     * Der Pfad geht ueber das Wertobjekt: die Spalte heisst weiter
     * `apportionable`, aber in DQL steht der Weg dorthin, nicht der
     * Spaltenname.
     */
    private static function restrictToApportionable(QueryBuilder $query, bool $apportionable): void
    {
        $query
            ->andWhere('COALESCE(c.apportionment.apportionable, k.apportionable) = :apportionable')
            ->setParameter('apportionable', $apportionable);
    }

    private static function restrictToSearch(QueryBuilder $query, CostItemFilter $filter): void
    {
        $conditions = ['LOWER(k.name) LIKE :needle'];
        $query->setParameter('needle', LikePattern::containing(mb_strtolower($filter->search ?? '')));

        if (null !== $filter->number) {
            $conditions[] = 'c.number = :number';
            $query->setParameter('number', $filter->number);
        }

        $query->andWhere(implode(' OR ', $conditions));
    }
}
