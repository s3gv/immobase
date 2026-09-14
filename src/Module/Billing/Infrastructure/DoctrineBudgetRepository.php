<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Billing\Infrastructure;

use App\Module\Billing\Domain\Budget;
use App\Module\Billing\Domain\BudgetApproval;
use App\Module\Billing\Domain\BudgetFilter;
use App\Module\Billing\Domain\BudgetIterationIsTaken;
use App\Module\Billing\Domain\BudgetRepository;
use App\Module\Billing\Domain\ResolutionStatus;
use App\Shared\Ui\Page;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use LogicException;

/**
 * Die Registry und nicht der Entity-Manager: nach einem gescheiterten
 * `flush()` schliesst Doctrine den Manager, und ein geschlossener Manager
 * kann nichts mehr — auch nicht die Seite zeichnen, auf der die Absage stehen
 * soll.
 */
final readonly class DoctrineBudgetRepository implements BudgetRepository
{
    /** Ab eins, wie jede sichtbare Nummer im Haus. */
    private const string SEQUENCE = 'billing_budget_number_seq';

    public function __construct(private ManagerRegistry $managers)
    {
    }

    public function save(Budget $budget): void
    {
        try {
            $this->manager()->persist($budget);
            $this->manager()->flush();
        } catch (UniqueConstraintViolationException) {
            $this->managers->resetManager();

            throw BudgetIterationIsTaken::already();
        }
    }

    public function remove(Budget $budget): void
    {
        $this->replaceApprovals($budget->id(), []);
        $this->manager()->remove($budget);
        $this->manager()->flush();
    }

    public function atomically(callable $work): mixed
    {
        return $this->manager()->wrapInTransaction($work);
    }

    public function byId(string $id): ?Budget
    {
        return $this->manager()->getRepository(Budget::class)->find($id);
    }

    public function nextNumber(): int
    {
        $next = $this->manager()->getConnection()->fetchOne("SELECT nextval('".self::SEQUENCE."')");

        return is_numeric($next) ? (int) $next : 1;
    }

    public function countMatching(BudgetFilter $filter): int
    {
        $count = NarrowedBudgets::of($this->manager(), $filter)
            ->select('COUNT(b.id)')
            ->getQuery()
            ->getSingleScalarResult();

        return is_numeric($count) ? (int) $count : 0;
    }

    public function matching(BudgetFilter $filter, Page $page): array
    {
        /** @var list<Budget> $budgets */
        $budgets = NarrowedBudgets::of($this->manager(), $filter)
            ->select('b')
            ->orderBy('b.createdAt', 'DESC')
            ->setFirstResult($page->offset())
            ->setMaxResults($page->limit())
            ->getQuery()
            ->getResult();

        return $budgets;
    }

    public function years(): array
    {
        /** @var list<array{year: int}> $rows */
        $rows = $this->manager()->createQueryBuilder()
            ->select('DISTINCT b.measure.firstYear AS year')
            ->from(Budget::class, 'b')
            ->orderBy('year', 'DESC')
            ->getQuery()
            ->getResult();

        return array_map(static fn (array $row): int => $row['year'], $rows);
    }

    public function iterationsOf(int $number): array
    {
        /** @var list<Budget> $budgets */
        $budgets = $this->manager()->createQueryBuilder()
            ->select('b', 'd')
            ->from(Budget::class, 'b')
            ->leftJoin('b.documents', 'd')
            ->where('b.edition.number = :number')
            ->setParameter('number', $number)
            ->orderBy('b.edition.iteration', 'ASC')
            ->getQuery()
            ->getResult();

        return $budgets;
    }

    public function decidedFor(string $propertyId): array
    {
        /** @var list<Budget> $budgets */
        $budgets = $this->manager()->createQueryBuilder()
            ->select('b')
            ->from(Budget::class, 'b')
            ->where('b.propertyId = :property')
            ->andWhere('b.stage.status = :decided')
            ->setParameter('property', $propertyId)
            ->setParameter('decided', ResolutionStatus::Released->value)
            ->orderBy('b.edition.number', 'ASC')
            ->addOrderBy('b.edition.iteration', 'ASC')
            ->getQuery()
            ->getResult();

        return $budgets;
    }

    public function approvalsOf(string $budgetId): array
    {
        /** @var list<BudgetApproval> $approvals */
        $approvals = $this->manager()->getRepository(BudgetApproval::class)
            ->findBy(['budgetId' => $budgetId]);

        return array_values(array_map(
            static fn (BudgetApproval $approval): string => $approval->unitId(),
            $approvals,
        ));
    }

    public function replaceApprovals(string $budgetId, array $unitIds): void
    {
        foreach ($this->manager()->getRepository(BudgetApproval::class)->findBy(['budgetId' => $budgetId]) as $known) {
            $this->manager()->remove($known);
        }

        $this->manager()->flush();

        foreach (array_unique($unitIds) as $unitId) {
            $this->manager()->persist(new BudgetApproval($budgetId, $unitId));
        }

        $this->manager()->flush();
    }

    private function manager(): EntityManagerInterface
    {
        $manager = $this->managers->getManagerForClass(Budget::class);

        return $manager instanceof EntityManagerInterface
            ? $manager
            : throw new LogicException('Für die Budgetpläne ist kein Entity-Manager zuständig.');
    }
}
