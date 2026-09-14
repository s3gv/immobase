<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Audit\Infrastructure;

use App\Module\Audit\Domain\AuditEntry;
use App\Module\Audit\Domain\AuditFilter;
use App\Module\Audit\Domain\AuditRepository;
use App\Shared\Search\LikePattern;
use App\Shared\Ui\Page;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;

final readonly class DoctrineAuditRepository implements AuditRepository
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    /**
     * Geschrieben wird an der Arbeitseinheit vorbei.
     *
     * **Und das ist der Kern der Sache.** Die Eintraege entstehen waehrend
     * eines `flush()`; ein zweites `flush()` von dort aus faehrt Doctrine in
     * denselben Vorgang hinein, waehrend er noch laeuft — geplante
     * Sammlungsaenderungen werden dann ein zweites Mal ausgefuehrt oder
     * vorzeitig verworfen. Gesehen an zwei Stellen: eine Rollenzuweisung kam
     * nicht an, und eine abgelehnte Loeschung wurde plotzlich ausgefuehrt.
     *
     * Ein Protokoll braucht die Arbeitseinheit ohnehin nicht: es haengt an
     * nichts, aendert nie etwas und wird nur angehaengt. Dieselbe Verbindung
     * genuegt — und damit faellt eine Zeile mit zurueck, wenn der Vorgang,
     * den sie festhaelt, zurueckgenommen wird. Das ist richtig so: was nicht
     * geschehen ist, steht auch nicht im Protokoll.
     */
    public function append(array $entries): void
    {
        $connection = $this->entityManager->getConnection();

        foreach ($entries as $entry) {
            $connection->insert('audit_entry', [
                'id' => $entry->id(),
                'at' => $entry->at()->format('Y-m-d H:i:s'),
                'action' => $entry->action()->value,
                'actor' => $entry->actor(),
                'actor_kind' => $entry->actorKind()->value,
                'record' => $entry->record(),
                'record_id' => $entry->recordId(),
                'label' => $entry->label(),
            ]);
        }
    }

    public function countMatching(AuditFilter $filter): int
    {
        $count = $this->restricted($filter, 'COUNT(a.id)')->getQuery()->getSingleScalarResult();

        return is_numeric($count) ? (int) $count : 0;
    }

    public function matching(AuditFilter $filter, Page $page): array
    {
        /** @var list<AuditEntry> $found */
        $found = $this->restricted($filter, 'a')
            ->orderBy('a.at', 'DESC')
            ->setFirstResult($page->offset())
            ->setMaxResults($page->limit())
            ->getQuery()
            ->getResult();

        return $found;
    }

    public function all(): array
    {
        /** @var list<AuditEntry> $found */
        $found = $this->restricted(AuditFilter::none(), 'a')
            ->orderBy('a.at', 'DESC')
            ->getQuery()
            ->getResult();

        return $found;
    }

    public function forgetBefore(DateTimeImmutable $day): int
    {
        $gone = $this->entityManager->createQueryBuilder()
            ->delete(AuditEntry::class, 'a')
            ->where('a.at < :day')
            ->setParameter('day', $day)
            ->getQuery()
            ->execute();

        return is_numeric($gone) ? (int) $gone : 0;
    }

    private function restricted(AuditFilter $filter, string $select): QueryBuilder
    {
        $query = $this->entityManager->createQueryBuilder()
            ->select($select)
            ->from(AuditEntry::class, 'a');

        if (null !== $filter->action) {
            $query->andWhere('a.action = :action')->setParameter('action', $filter->action->value);
        }

        if (null !== $filter->search) {
            $query->andWhere('LOWER(a.actor) LIKE :term OR LOWER(a.label) LIKE :term OR LOWER(a.record) LIKE :term')
                ->setParameter('term', LikePattern::containing($filter->search));
        }

        return $query;
    }
}
