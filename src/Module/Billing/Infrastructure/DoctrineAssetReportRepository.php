<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Billing\Infrastructure;

use App\Module\Billing\Domain\AssetReport;
use App\Module\Billing\Domain\AssetReportFilter;
use App\Module\Billing\Domain\AssetReportIterationIsTaken;
use App\Module\Billing\Domain\AssetReportRepository;
use App\Module\Billing\Domain\StatementStatus;
use App\Shared\Ui\Page;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use LogicException;

/**
 * Die Registry und nicht der Entity-Manager: nach einem gescheiterten
 * `flush()` schliesst Doctrine den Manager, und ein geschlossener Manager
 * kann nichts mehr — auch nicht die Seite zeichnen, auf der die Absage stehen
 * soll. Zwei gleichzeitige Berichtigungen desselben Berichts sind so ein Fall.
 */
final readonly class DoctrineAssetReportRepository implements AssetReportRepository
{
    /** Ab eins, wie jede sichtbare Nummer im Haus. */
    private const string SEQUENCE = 'billing_asset_report_number_seq';

    public function __construct(private ManagerRegistry $managers)
    {
    }

    public function save(AssetReport $report): void
    {
        try {
            $this->manager()->persist($report);
            $this->manager()->flush();
        } catch (UniqueConstraintViolationException) {
            $this->managers->resetManager();

            throw AssetReportIterationIsTaken::already();
        }
    }

    public function remove(AssetReport $report): void
    {
        $this->manager()->remove($report);
        $this->manager()->flush();
    }

    public function byId(string $id): ?AssetReport
    {
        return $this->manager()->getRepository(AssetReport::class)->find($id);
    }

    /**
     * Aus der Sequenz und nicht aus `MAX(number) + 1`.
     *
     * Zwei gleichzeitig angelegte Berichte lesen sonst dieselbe hoechste
     * Nummer, und der zweite laeuft in den eindeutigen Index.
     */
    public function nextNumber(): int
    {
        $next = $this->manager()->getConnection()
            ->fetchOne("SELECT nextval('".self::SEQUENCE."')");

        return is_numeric($next) ? (int) $next : 1;
    }

    public function countMatching(AssetReportFilter $filter): int
    {
        $count = NarrowedAssetReports::of($this->manager(), $filter)
            ->select('COUNT(r.id)')
            ->getQuery()
            ->getSingleScalarResult();

        return is_numeric($count) ? (int) $count : 0;
    }

    public function matching(AssetReportFilter $filter, Page $page): array
    {
        /** @var list<AssetReport> $reports */
        $reports = NarrowedAssetReports::of($this->manager(), $filter)
            ->select('r')
            ->orderBy('r.createdAt', 'DESC')
            ->setFirstResult($page->offset())
            ->setMaxResults($page->limit())
            ->getQuery()
            ->getResult();

        return $reports;
    }

    public function years(): array
    {
        /** @var list<array{year: int}> $rows */
        $rows = $this->manager()->createQueryBuilder()
            ->select('DISTINCT r.period.year AS year')
            ->from(AssetReport::class, 'r')
            ->orderBy('year', 'DESC')
            ->getQuery()
            ->getResult();

        return array_map(static fn (array $row): int => $row['year'], $rows);
    }

    public function iterationsOf(int $number): array
    {
        /** @var list<AssetReport> $reports */
        $reports = $this->manager()->createQueryBuilder()
            ->select('r', 'd')
            ->from(AssetReport::class, 'r')
            ->leftJoin('r.documents', 'd')
            ->where('r.edition.number = :number')
            ->setParameter('number', $number)
            ->orderBy('r.edition.iteration', 'ASC')
            ->getQuery()
            ->getResult();

        return $reports;
    }

    public function lastReleasedBefore(string $propertyId, int $fiscalYear): ?AssetReport
    {
        /** @var list<AssetReport> $reports */
        $reports = $this->manager()->createQueryBuilder()
            ->select('r')
            ->from(AssetReport::class, 'r')
            ->where('r.propertyId = :property')
            ->andWhere('r.period.year < :year')
            ->andWhere('r.release.status = :released')
            ->setParameter('property', $propertyId)
            ->setParameter('year', $fiscalYear)
            ->setParameter('released', StatementStatus::Released->value)
            ->orderBy('r.period.year', 'DESC')
            ->addOrderBy('r.edition.iteration', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getResult();

        return $reports[0] ?? null;
    }

    private function manager(): EntityManagerInterface
    {
        $manager = $this->managers->getManagerForClass(AssetReport::class);

        return $manager instanceof EntityManagerInterface
            ? $manager
            : throw new LogicException('Für die Vermögensberichte ist kein Entity-Manager zuständig.');
    }
}
