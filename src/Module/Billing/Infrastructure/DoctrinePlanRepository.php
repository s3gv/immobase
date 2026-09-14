<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Billing\Infrastructure;

use App\Module\Billing\Domain\Plan;
use App\Module\Billing\Domain\PlanFilter;
use App\Module\Billing\Domain\PlanIterationIsTaken;
use App\Module\Billing\Domain\PlanRepository;
use App\Module\Billing\Domain\PlanSource;
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
 * soll. Zwei gleichzeitige Korrekturen desselben Plans sind so ein Fall.
 */
final readonly class DoctrinePlanRepository implements PlanRepository
{
    /** Ab eins, wie jede sichtbare Nummer im Haus. */
    private const string SEQUENCE = 'billing_plan_number_seq';

    public function __construct(private ManagerRegistry $managers)
    {
    }

    /**
     * @throws PlanIterationIsTaken
     */
    public function save(Plan $plan): void
    {
        try {
            $this->manager()->persist($plan);
            $this->manager()->flush();
        } catch (UniqueConstraintViolationException $clash) {
            $this->managers->resetManager();

            throw PlanIterationIsTaken::because($clash);
        }
    }

    public function remove(Plan $plan): void
    {
        $this->manager()->remove($plan);
        $this->manager()->flush();
    }

    public function atomically(callable $work): mixed
    {
        return $this->manager()->wrapInTransaction($work);
    }

    public function byId(string $id): ?Plan
    {
        return $this->manager()->getRepository(Plan::class)->find($id);
    }

    /**
     * Aus der Sequenz und nicht aus `MAX(number) + 1`.
     *
     * Zwei gleichzeitig angelegte Plaene lesen sonst dieselbe hoechste Nummer,
     * und der zweite laeuft in den eindeutigen Index.
     */
    public function nextNumber(): int
    {
        $next = $this->manager()->getConnection()
            ->fetchOne("SELECT nextval('".self::SEQUENCE."')");

        return is_numeric($next) ? (int) $next : 1;
    }

    public function countMatching(PlanFilter $filter): int
    {
        $count = NarrowedPlans::of($this->manager(), $filter)
            ->select('COUNT(p.id)')
            ->getQuery()
            ->getSingleScalarResult();

        return is_numeric($count) ? (int) $count : 0;
    }

    public function matching(PlanFilter $filter, Page $page): array
    {
        /** @var list<Plan> $plans */
        $plans = NarrowedPlans::of($this->manager(), $filter)
            ->select('p')
            ->orderBy('p.createdAt', 'DESC')
            ->setFirstResult($page->offset())
            ->setMaxResults($page->limit())
            ->getQuery()
            ->getResult();

        return $plans;
    }

    public function years(): array
    {
        /** @var list<array{year: int}> $rows */
        $rows = $this->manager()->createQueryBuilder()
            ->select('DISTINCT p.period.year AS year')
            ->from(Plan::class, 'p')
            ->orderBy('year', 'DESC')
            ->getQuery()
            ->getResult();

        return array_map(static fn (array $row): int => $row['year'], $rows);
    }

    public function released(): array
    {
        /** @var list<Plan> $plans */
        $plans = $this->manager()->createQueryBuilder()
            ->select('p', 'd')
            ->from(Plan::class, 'p')
            ->leftJoin('p.documents', 'd')
            ->where('p.stage.status = :released')
            ->setParameter('released', ResolutionStatus::Released->value)
            ->orderBy('p.createdAt', 'DESC')
            ->getQuery()
            ->getResult();

        return $plans;
    }

    public function iterationsOf(int $number): array
    {
        /** @var list<Plan> $plans */
        $plans = $this->manager()->createQueryBuilder()
            ->select('p', 'd')
            ->from(Plan::class, 'p')
            ->leftJoin('p.documents', 'd')
            ->where('p.edition.number = :number')
            ->setParameter('number', $number)
            ->orderBy('p.edition.iteration', 'ASC')
            ->getQuery()
            ->getResult();

        return $plans;
    }

    public function sourcesOf(string $planId): array
    {
        /** @var list<PlanSource> $sources */
        $sources = $this->manager()->getRepository(PlanSource::class)
            ->findBy(['planId' => $planId]);

        return array_values($sources);
    }

    public function replaceSources(string $planId, array $sources): void
    {
        foreach ($this->sourcesOf($planId) as $known) {
            $this->manager()->remove($known);
        }

        $this->manager()->flush();

        foreach ($sources as $source) {
            $this->manager()->persist($source);
        }

        $this->manager()->flush();
    }

    public function holders(array $sourceIds): array
    {
        return WhoHoldsAPlanSource::among($this->manager(), $sourceIds);
    }

    private function manager(): EntityManagerInterface
    {
        $manager = $this->managers->getManagerForClass(Plan::class);

        return $manager instanceof EntityManagerInterface
            ? $manager
            : throw new LogicException('Für die Wirtschaftspläne ist kein Entity-Manager zuständig.');
    }
}
