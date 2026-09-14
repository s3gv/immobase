<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Portal\Infrastructure;

use App\Module\Portal\Domain\Enquiry;
use App\Module\Portal\Domain\EnquiryFilter;
use App\Module\Portal\Domain\EnquiryRepository;
use App\Module\Portal\Domain\EnquiryState;
use App\Shared\Search\LikePattern;
use App\Shared\Ui\Page;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;
use RuntimeException;

final readonly class DoctrineEnquiryRepository implements EnquiryRepository
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function save(Enquiry $enquiry): void
    {
        $this->entityManager->persist($enquiry);
        $this->entityManager->flush();
    }

    public function byId(string $id): ?Enquiry
    {
        return $this->entityManager->getRepository(Enquiry::class)->find($id);
    }

    public function nextNumber(): int
    {
        $next = $this->entityManager->getConnection()->fetchOne("SELECT nextval('portal_enquiry_number_seq')");

        if (!is_numeric($next)) {
            throw new RuntimeException('Die Sequenz portal_enquiry_number_seq hat keine Nummer geliefert.');
        }

        return (int) $next;
    }

    public function forParty(string $partyId): array
    {
        /** @var list<Enquiry> $enquiries */
        $enquiries = $this->entityManager->createQueryBuilder()
            ->select('e')
            ->from(Enquiry::class, 'e')
            ->where('e.partyId = :party')
            ->setParameter('party', $partyId)
            ->orderBy('e.lastMessageAt', 'DESC')
            ->getQuery()
            ->getResult();

        return $enquiries;
    }

    public function countMatching(EnquiryFilter $filter): int
    {
        $count = $this->restricted($filter, 'COUNT(e.id)')->getQuery()->getSingleScalarResult();

        return is_numeric($count) ? (int) $count : 0;
    }

    public function matching(EnquiryFilter $filter, Page $page): array
    {
        /** @var list<Enquiry> $enquiries */
        $enquiries = $this->restricted($filter, 'e')
            // Was auf uns wartet, steht oben — danach das Juengste.
            ->addSelect('CASE WHEN e.state = :waiting THEN 0 ELSE 1 END AS HIDDEN rank')
            ->setParameter('waiting', EnquiryState::Open->value)
            ->orderBy('rank', 'ASC')
            ->addOrderBy('e.lastMessageAt', 'DESC')
            ->setFirstResult($page->offset())
            ->setMaxResults($page->limit())
            ->getQuery()
            ->getResult();

        return $enquiries;
    }

    public function countUnreadByStaff(): int
    {
        $count = $this->entityManager->createQueryBuilder()
            ->select('COUNT(e.id)')
            ->from(Enquiry::class, 'e')
            ->where('e.readByStaffAt IS NULL OR e.readByStaffAt < e.lastMessageAt')
            ->getQuery()
            ->getSingleScalarResult();

        return is_numeric($count) ? (int) $count : 0;
    }

    public function tallyOf(DateTimeImmutable $since): array
    {
        /** @var array{open: int|string, unread: int|string, answered: string|null} $row */
        $row = $this->entityManager->getConnection()->fetchAssociative(
            'SELECT
                COUNT(*) FILTER (WHERE state = :open) AS open,
                COUNT(*) FILTER (WHERE read_by_staff_at IS NULL OR read_by_staff_at < last_message_at) AS unread,
                AVG(EXTRACT(EPOCH FROM (first_answer_at - created_at)))
                    FILTER (WHERE first_answer_at IS NOT NULL AND created_at >= :since) AS answered
             FROM portal_enquiry',
            ['open' => EnquiryState::Open->value, 'since' => $since->format('Y-m-d H:i:s')],
        );

        if (false === $row) {
            return ['open' => 0, 'unread' => 0, 'answeredSeconds' => null];
        }

        return [
            'open' => (int) $row['open'],
            'unread' => (int) $row['unread'],
            'answeredSeconds' => null === $row['answered'] ? null : (int) round((float) $row['answered']),
        ];
    }

    public function withNotificationDue(DateTimeImmutable $on): array
    {
        $query = $this->entityManager->createQueryBuilder()
            ->select('e')
            ->from(Enquiry::class, 'e')
            ->where('e.notifyDueAt IS NOT NULL')
            ->andWhere('e.notifyDueAt <= :now')
            ->setParameter('now', $on);

        // Wer zwischenzeitlich gelesen hat, hat den Termin selbst geloescht —
        // diese Bedingung ist der Guertel dazu.
        self::onlyUnread($query, 'e.readByPartyAt');

        /** @var list<Enquiry> $enquiries */
        $enquiries = $query->getQuery()->getResult();

        return $enquiries;
    }

    public function discardFor(string $partyId): void
    {
        // Ueber die Entities und nicht als eine Anweisung: die Anhaenge und
        // Nachrichten haengen an Kaskaden, und eine DQL-Loeschung geht an
        // ihnen vorbei.
        foreach ($this->forParty($partyId) as $enquiry) {
            $this->entityManager->remove($enquiry);
        }

        $this->entityManager->flush();
    }

    private function restricted(EnquiryFilter $filter, string $select): QueryBuilder
    {
        $query = $this->entityManager->createQueryBuilder()
            ->select($select)
            ->from(Enquiry::class, 'e');

        if (null !== $filter->state) {
            $query->andWhere('e.state = :state')->setParameter('state', $filter->state->value);
        }

        if ($filter->unreadOnly) {
            self::onlyUnread($query, 'e.readByStaffAt');
        }

        if (null !== $filter->assigneeUserId) {
            $query->andWhere('e.assigneeUserId = :assignee')->setParameter('assignee', $filter->assigneeUserId);
        }

        if (null !== $filter->search) {
            $this->restrictToSearch($query, $filter->search);
        }

        return $query;
    }

    /**
     * „Noch nicht gelesen" — nie gelesen oder seither etwas Neues.
     *
     * Als geklammerter Ausdruck und nicht als Zeichenkette mit einem ODER
     * darin: `andWhere('A OR B')` haengt unverklammert an, und aus
     * `X AND (A OR B)` wird `(X AND A) OR B`. Die Abfrage liefert dann
     * gelegentlich zu viel und gelegentlich zu wenig — je nachdem, welche
     * Filter noch dabei sind. Genau das ist hier passiert.
     */
    private static function onlyUnread(QueryBuilder $query, string $readAt): void
    {
        $query->andWhere($query->expr()->orX(
            $query->expr()->isNull($readAt),
            $query->expr()->lt($readAt, 'e.lastMessageAt'),
        ));
    }

    /**
     * Ueber Betreff und Nummer — nicht ueber den Inhalt der Nachrichten.
     *
     * Ein Gespraech ist Post an einen Menschen. Eine Volltextsuche darueber
     * ist etwas anderes als eine Liste von Vorgaengen, und wer sie braucht,
     * soll sie ausdruecklich bekommen und nicht nebenbei.
     */
    private function restrictToSearch(QueryBuilder $query, string $search): void
    {
        $conditions = ['LOWER(e.subject) LIKE :needle'];
        $query->setParameter('needle', LikePattern::containing(mb_strtolower($search)));

        if (ctype_digit($search)) {
            $conditions[] = 'e.number = :number';
            $query->setParameter('number', (int) $search);
        }

        $query->andWhere(implode(' OR ', $conditions));
    }
}
