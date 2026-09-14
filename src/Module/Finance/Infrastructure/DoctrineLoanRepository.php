<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Finance\Infrastructure;

use App\Module\Finance\Domain\Loan;
use App\Module\Finance\Domain\LoanFilter;
use App\Module\Finance\Domain\LoanRepository;
use App\Shared\Search\LikePattern;
use App\Shared\Ui\Page;
use App\Shared\Ui\Sort;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;
use RuntimeException;

final readonly class DoctrineLoanRepository implements LoanRepository
{
    /** Was sich sortieren laesst — und wie es in der Abfrage heisst. */
    public const array SORTABLE = [
        'nummer' => 'l.number',
        'bezeichnung' => 'l.label',
        'bank' => 'l.lender',
    ];

    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function save(Loan $loan): void
    {
        $this->entityManager->persist($loan);
        $this->entityManager->flush();
    }

    public function remove(Loan $loan): void
    {
        $this->entityManager->remove($loan);
        $this->entityManager->flush();
    }

    public function byId(string $id): ?Loan
    {
        return $this->entityManager->getRepository(Loan::class)->find($id);
    }

    public function byNumber(int $number): ?Loan
    {
        return $this->entityManager->getRepository(Loan::class)->findOneBy(['number' => $number]);
    }

    public function byReference(string $reference): ?Loan
    {
        // Eine leere Referenz traegt jedes von Hand angelegte Darlehen; sie
        // beantwortet die Frage „welches gehoert zu diesem Beschluss" nicht.
        return '' === $reference
            ? null
            : $this->entityManager->getRepository(Loan::class)->findOneBy(['reference' => $reference]);
    }

    public function nextNumber(): int
    {
        $next = $this->entityManager->getConnection()->fetchOne("SELECT nextval('finance_loan_number_seq')");

        if (!is_numeric($next)) {
            throw new RuntimeException('Die Nummernfolge der Darlehen antwortet nicht.');
        }

        return (int) $next;
    }

    public function all(): array
    {
        return $this->loansWhere(null, '');
    }

    public function forProperty(string $propertyId): array
    {
        return $this->loansWhere('propertyId', $propertyId);
    }

    public function countMatching(LoanFilter $filter): int
    {
        $count = $this->restricted($filter, 'COUNT(l.id)')->getQuery()->getSingleScalarResult();

        return is_numeric($count) ? (int) $count : 0;
    }

    public function matching(LoanFilter $filter, Page $page, Sort $sort): array
    {
        /** @var list<Loan> $loans */
        $loans = $this->restricted($filter, 'l')
            ->orderBy(self::SORTABLE[$sort->field] ?? 'l.number', $sort->sql())
            ->addOrderBy('l.number', 'DESC')
            ->setFirstResult($page->offset())
            ->setMaxResults($page->limit())
            ->getQuery()
            ->getResult();

        return $loans;
    }

    /**
     * Die Auswahl, so wie sie die Liste einschraenkt.
     *
     * Ohne die Ereignisse: die Seite rechnet danach je Darlehen den Plan und
     * holt sie dabei nach. Ein `leftJoin` auf eine Sammlung und `setMaxResults`
     * vertragen sich nicht — Doctrine zaehlte dann Zeilen und nicht Darlehen.
     */
    private function restricted(LoanFilter $filter, string $select): QueryBuilder
    {
        $query = $this->entityManager->createQueryBuilder()
            ->select($select)
            ->from(Loan::class, 'l');

        if (null !== $filter->propertyId) {
            $query->andWhere('l.propertyId = :property')->setParameter('property', $filter->propertyId);
        }

        if (null !== $filter->search) {
            self::restrictToSearch($query, $filter);
        }

        return $query;
    }

    private static function restrictToSearch(QueryBuilder $query, LoanFilter $filter): void
    {
        $conditions = ['LOWER(l.label) LIKE :needle', 'LOWER(l.lender) LIKE :needle'];
        $query->setParameter('needle', LikePattern::containing(mb_strtolower($filter->search ?? '')));

        if (null !== $filter->number) {
            $conditions[] = 'l.number = :number';
            $query->setParameter('number', $filter->number);
        }

        $query->andWhere(implode(' OR ', $conditions));
    }

    /**
     * Die Darlehen samt ihren Ereignissen — ohne Feld: alle.
     *
     * Mitgeladen, weil jeder Aufrufer den Tilgungsplan rechnet und der die
     * Ereignisse braucht: ohne das waere es je Darlehen eine weitere Abfrage.
     *
     * @return list<Loan>
     */
    private function loansWhere(?string $field, string $value): array
    {
        $query = $this->entityManager->createQueryBuilder()
            ->select('l', 'e')
            ->from(Loan::class, 'l')
            ->leftJoin('l.events', 'e')
            ->orderBy('l.number', 'DESC');

        if (null !== $field) {
            $query->where('l.'.$field.' = :value')->setParameter('value', $value);
        }

        /** @var list<Loan> $loans */
        $loans = $query->getQuery()->getResult();

        return $loans;
    }
}
