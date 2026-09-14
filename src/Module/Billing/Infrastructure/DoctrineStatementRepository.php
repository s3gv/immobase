<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Billing\Infrastructure;

use App\Module\Billing\Domain\Statement;
use App\Module\Billing\Domain\StatementFilter;
use App\Module\Billing\Domain\StatementIterationIsTaken;
use App\Module\Billing\Domain\StatementRepository;
use App\Module\Billing\Domain\StatementSource;
use App\Module\Billing\Domain\StatementStatus;
use App\Module\Billing\Domain\WithoutPdf;
use App\Shared\Ui\Page;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use LogicException;

/**
 * Die Registry und nicht der Entity-Manager: nach einem gescheiterten
 * `flush()` schliesst Doctrine den Manager, und ein geschlossener Manager
 * kann nichts mehr — auch nicht die Seite zeichnen, auf der die Absage
 * stehen soll. Zwei gleichzeitige Korrekturen derselben Abrechnung sind
 * genau so ein Fall.
 */
final readonly class DoctrineStatementRepository implements StatementRepository
{
    /** Ab eins, wie jede sichtbare Nummer im Haus. */
    private const string SEQUENCE = 'billing_statement_number_seq';

    public function __construct(private ManagerRegistry $managers)
    {
    }

    /**
     * @throws StatementIterationIsTaken
     */
    public function save(Statement $statement): void
    {
        try {
            $this->manager()->persist($statement);
            $this->manager()->flush();
        } catch (UniqueConstraintViolationException $clash) {
            $this->managers->resetManager();

            throw StatementIterationIsTaken::because($clash);
        }
    }

    public function remove(Statement $statement): void
    {
        $this->manager()->remove($statement);
        $this->manager()->flush();
    }

    public function byId(string $id): ?Statement
    {
        return $this->manager()->getRepository(Statement::class)->find($id);
    }

    /**
     * Aus der Sequenz und nicht aus `MAX(number) + 1`.
     *
     * Zwei gleichzeitige Laeufe lesen sonst dieselbe hoechste Nummer, und der
     * zweite laeuft in den eindeutigen Index.
     */
    public function nextNumber(): int
    {
        $next = $this->manager()->getConnection()
            ->fetchOne("SELECT nextval('".self::SEQUENCE."')");

        return is_numeric($next) ? (int) $next : 1;
    }

    public function countMatching(StatementFilter $filter): int
    {
        $count = NarrowedStatements::of($this->manager(), $filter)
            ->select('COUNT(s.id)')
            ->getQuery()
            ->getSingleScalarResult();

        return is_numeric($count) ? (int) $count : 0;
    }

    public function matching(StatementFilter $filter, Page $page): array
    {
        /** @var list<Statement> $statements */
        $statements = NarrowedStatements::of($this->manager(), $filter)
            ->select('s')
            ->orderBy('s.createdAt', 'DESC')
            ->setFirstResult($page->offset())
            ->setMaxResults($page->limit())
            ->getQuery()
            ->getResult();

        return $statements;
    }

    public function years(): array
    {
        /** @var list<array{year: int}> $rows */
        $rows = $this->manager()->createQueryBuilder()
            ->select('DISTINCT s.period.year AS year')
            ->from(Statement::class, 's')
            ->orderBy('year', 'DESC')
            ->getQuery()
            ->getResult();

        return array_map(static fn (array $row): int => $row['year'], $rows);
    }

    public function released(): array
    {
        /** @var list<Statement> $statements */
        $statements = $this->manager()->createQueryBuilder()
            ->select('s', 'd')
            ->from(Statement::class, 's')
            ->leftJoin('s.documents', 'd')
            ->where('s.release.status = :released')
            ->setParameter('released', StatementStatus::Released->value)
            ->orderBy('s.createdAt', 'DESC')
            ->getQuery()
            ->getResult();

        return $statements;
    }

    public function sourcesOf(string $statementId): array
    {
        /** @var list<StatementSource> $sources */
        $sources = $this->manager()->getRepository(StatementSource::class)
            ->findBy(['statementId' => $statementId]);

        return array_values($sources);
    }

    public function replaceSources(string $statementId, array $sources): void
    {
        foreach ($this->sourcesOf($statementId) as $known) {
            $this->manager()->remove($known);
        }

        $this->manager()->flush();

        foreach ($sources as $source) {
            $this->manager()->persist($source);
        }

        $this->manager()->flush();
    }

    public function iterationsOf(int $number): array
    {
        /** @var list<Statement> $statements */
        $statements = $this->manager()->createQueryBuilder()
            ->select('s', 'd')
            ->from(Statement::class, 's')
            ->leftJoin('s.documents', 'd')
            ->where('s.number = :number')
            ->setParameter('number', $number)
            ->orderBy('s.iteration', 'ASC')
            ->getQuery()
            ->getResult();

        return $statements;
    }

    public function withoutPdf(string $statementId): array
    {
        /** @var list<WithoutPdf> $rows */
        $rows = $this->manager()->getRepository(WithoutPdf::class)
            ->findBy(['statementId' => $statementId]);

        return array_values(array_map(
            static fn (WithoutPdf $row): string => $row->recipientKey(),
            $rows,
        ));
    }

    public function keepWithoutPdf(string $statementId, array $recipientKeys): void
    {
        /** @var list<WithoutPdf> $rows */
        $rows = $this->manager()->getRepository(WithoutPdf::class)
            ->findBy(['statementId' => $statementId]);

        foreach ($rows as $row) {
            $this->manager()->remove($row);
        }

        $this->manager()->flush();

        foreach ($recipientKeys as $key) {
            $this->manager()->persist(new WithoutPdf($statementId, $key));
        }

        $this->manager()->flush();
    }

    public function holders(array $sourceIds): array
    {
        return WhoHoldsASource::among($this->manager(), $sourceIds);
    }

    private function manager(): EntityManagerInterface
    {
        $manager = $this->managers->getManagerForClass(Statement::class);

        return $manager instanceof EntityManagerInterface
            ? $manager
            : throw new LogicException('Für die Abrechnungen ist kein Entity-Manager zuständig.');
    }
}
