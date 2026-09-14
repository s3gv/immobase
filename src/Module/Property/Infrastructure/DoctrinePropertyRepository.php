<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Property\Infrastructure;

use App\Module\Property\Domain\ManagementModes;
use App\Module\Property\Domain\Property;
use App\Module\Property\Domain\PropertyFilter;
use App\Module\Property\Domain\PropertyRepository;
use App\Module\Property\Domain\PropertyStatus;
use App\Shared\Search\LikePattern;
use App\Shared\Search\SearchTerm;
use App\Shared\Ui\Page;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;
use RuntimeException;

final readonly class DoctrinePropertyRepository implements PropertyRepository
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function save(Property $property): void
    {
        $this->entityManager->persist($property);
        $this->entityManager->flush();
    }

    /**
     * @template T
     *
     * @param callable(): T $work
     *
     * @return T
     */
    public function atomically(callable $work): mixed
    {
        return $this->entityManager->wrapInTransaction($work);
    }

    public function remove(Property $property): void
    {
        $this->entityManager->remove($property);
        $this->entityManager->flush();
    }

    public function byId(string $id): ?Property
    {
        return $this->entityManager->getRepository(Property::class)->find($id);
    }

    public function byNumber(int $number): ?Property
    {
        return $this->entityManager->getRepository(Property::class)->findOneBy(['number' => $number]);
    }

    public function nextNumber(): int
    {
        $next = $this->entityManager->getConnection()->fetchOne("SELECT nextval('property_number_seq')");

        if (!is_numeric($next)) {
            throw new RuntimeException('Die Sequenz property_number_seq hat keine Nummer geliefert.');
        }

        return (int) $next;
    }

    public function anywhere(SearchTerm $term, int $limit): array
    {
        /** @var list<Property> $found */
        $found = SearchedProperties::of($this->entityManager, $term, $limit)->getQuery()->getResult();

        return $found;
    }

    public function countWithoutAnAccount(): int
    {
        $count = $this->entityManager->createQueryBuilder()
            ->select('COUNT(p.id)')
            ->from(Property::class, 'p')
            ->where("p.accounting.account.iban = ''")
            ->andWhere('p.management.status <> :ended')
            ->setParameter('ended', PropertyStatus::Ended->value)
            ->getQuery()
            ->getSingleScalarResult();

        return is_numeric($count) ? (int) $count : 0;
    }

    public function countMatching(PropertyFilter $filter): int
    {
        $count = $this->restricted($filter, 'COUNT(p.id)')->getQuery()->getSingleScalarResult();

        return is_numeric($count) ? (int) $count : 0;
    }

    public function matching(PropertyFilter $filter, Page $page): array
    {
        /** @var list<Property> $properties */
        $properties = $this->restricted($filter, 'p')
            ->orderBy('p.number', 'ASC')
            ->setFirstResult($page->offset())
            ->setMaxResults($page->limit())
            ->getQuery()
            ->getResult();

        return $properties;
    }

    public function unitCounts(array $propertyIds): array
    {
        if ([] === $propertyIds) {
            return [];
        }

        /** @var list<array{property_id: string, units: int|string}> $rows */
        $rows = $this->entityManager->getConnection()->fetchAllAssociative(
            'SELECT property_id, COUNT(*) AS units FROM property_unit
             WHERE property_id IN (:ids) GROUP BY property_id',
            ['ids' => $propertyIds],
            ['ids' => ArrayParameterType::STRING],
        );

        $counts = [];

        foreach ($rows as $row) {
            $counts[$row['property_id']] = (int) $row['units'];
        }

        return $counts;
    }

    public function owningParties(array $partyIds): array
    {
        if ([] === $partyIds) {
            return [];
        }

        /** @var list<string> $owning */
        $owning = $this->entityManager->getConnection()->fetchFirstColumn(
            'SELECT DISTINCT party_id FROM property_unit_owner WHERE party_id IN (:ids)',
            ['ids' => $partyIds],
            ['ids' => ArrayParameterType::STRING],
        );

        return $owning;
    }

    /**
     * Der Pfad geht ueber das eingebettete Objekt: p.modes.value, nicht
     * p.modes. Der falsche Pfad faellt erst zur Laufzeit auf.
     */
    private function restricted(PropertyFilter $filter, string $select): QueryBuilder
    {
        $query = $this->entityManager->createQueryBuilder()
            ->select($select)
            ->from(Property::class, 'p');

        if (null !== $filter->status) {
            $query->andWhere('p.management.status = :status')->setParameter('status', $filter->status);
        } elseif (!$filter->withPast) {
            // Abgewickelte stehen bereit, aber nicht im Weg.
            $query->andWhere('p.management.status <> :ended')->setParameter('ended', PropertyStatus::Ended);
        }

        if (null !== $filter->mode) {
            $query->andWhere('p.modes.value LIKE :mode')
                ->setParameter('mode', '%'.ManagementModes::marked($filter->mode->value).'%');
        }

        if (null !== $filter->search) {
            $this->restrictToSearch($query, $filter->search);
        }

        return $query;
    }

    /**
     * Sucht ueber Bezeichnung, Ort und — wenn die Eingabe eine Zahl ist — die
     * Objektnummer.
     */
    private function restrictToSearch(QueryBuilder $query, string $search): void
    {
        $conditions = ['LOWER(p.name) LIKE :search', 'LOWER(p.address.city) LIKE :search'];
        $query->setParameter('search', LikePattern::containing($search));

        if (ctype_digit($search)) {
            $conditions[] = 'p.number = :number';
            $query->setParameter('number', (int) $search);
        }

        $query->andWhere(implode(' OR ', $conditions));
    }
}
