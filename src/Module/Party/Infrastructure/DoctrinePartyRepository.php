<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Party\Infrastructure;

use App\Module\Party\Domain\Party;
use App\Module\Party\Domain\PartyFilter;
use App\Module\Party\Domain\PartyRepository;
use App\Module\Party\Domain\PartyRoles;
use App\Module\Party\Domain\PartyStatus;
use App\Shared\Search\LikePattern;
use App\Shared\Search\SearchTerm;
use App\Shared\Ui\Page;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;
use RuntimeException;

final readonly class DoctrinePartyRepository implements PartyRepository
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function save(Party $party): void
    {
        $this->entityManager->persist($party);
        $this->entityManager->flush();
    }

    public function remove(Party $party): void
    {
        $this->entityManager->remove($party);
        $this->entityManager->flush();
    }

    public function atomically(callable $work): mixed
    {
        return $this->entityManager->wrapInTransaction($work);
    }

    public function byId(string $id): ?Party
    {
        return $this->entityManager->getRepository(Party::class)->find($id);
    }

    public function byReference(int $reference): ?Party
    {
        return $this->entityManager->getRepository(Party::class)->findOneBy(['reference' => $reference]);
    }

    /**
     * Aus einer Sequenz, nicht aus MAX(reference) + 1.
     *
     * Zwischen dem Lesen des Hoechststandes und dem Speichern liegt eine
     * Luecke. Zwei gleichzeitig abgeschickte Abläufe lasen darin dieselbe
     * Nummer, und der zweite lief in den eindeutigen Index. nextval() vergibt
     * jede Nummer nur einmal — auch an zwei Verbindungen zugleich.
     */
    public function nextReference(): int
    {
        $next = $this->entityManager->getConnection()->fetchOne("SELECT nextval('party_reference_seq')");

        if (!is_numeric($next)) {
            throw new RuntimeException('Die Sequenz party_reference_seq hat keine Nummer geliefert.');
        }

        return (int) $next;
    }

    /**
     * Sucht ueber Namen und Nummer, aktive Datensaetze zuerst.
     *
     * Die Vervollstaendigung zeigt eine kurze Liste; wer dort einen
     * archivierten Kontakt sieht, hat ihn meist auch gemeint. Deshalb keine
     * Einschraenkung auf aktiv — nur die Reihenfolge.
     */
    public function search(string $term, int $limit): array
    {
        $query = $this->entityManager->createQueryBuilder()
            ->select('p')
            ->from(Party::class, 'p')
            ->orderBy('p.status', 'ASC')
            ->addOrderBy('p.partyName.sortName', 'ASC')
            ->setMaxResults($limit);

        $this->restrictToSearch($query, mb_strtolower($term));

        /** @var list<Party> $found */
        $found = $query->getQuery()->getResult();

        return $found;
    }

    /**
     * Die zentrale Suche ueber die Stammdaten.
     *
     * Die Abfrage steht in {@see SearchedParties}; hier bleibt, was danach
     * kommt — aus Kennungen werden Entitaeten, und die Reihenfolge der
     * Abfrage wird wiederhergestellt, denn `IN (…)` gibt sie in keiner
     * bestimmten heraus.
     */
    public function anywhere(SearchTerm $term, int $limit): array
    {
        $ids = SearchedParties::of($this->entityManager->getConnection(), $term, $limit);

        return SearchedParties::inTheOrderOf($ids, $this->byIds($ids));
    }

    public function byIds(array $ids): array
    {
        if ([] === $ids) {
            return [];
        }

        /** @var list<Party> $parties */
        $parties = $this->entityManager->createQueryBuilder()
            ->select('p')
            ->from(Party::class, 'p')
            ->where('p.id IN (:ids)')
            ->setParameter('ids', $ids)
            ->getQuery()
            ->getResult();

        return $parties;
    }

    public function countMatching(PartyFilter $filter): int
    {
        $count = $this->restricted($filter, 'COUNT(p.id)')->getQuery()->getSingleScalarResult();

        return is_numeric($count) ? (int) $count : 0;
    }

    public function matching(PartyFilter $filter, Page $page): array
    {
        /** @var list<Party> $parties */
        $parties = $this->restricted($filter, 'p')
            ->orderBy('p.partyName.sortName', 'ASC')
            ->setFirstResult($page->offset())
            ->setMaxResults($page->limit())
            ->getQuery()
            ->getResult();

        return $parties;
    }

    /**
     * Baut die Abfrage mit den Einschraenkungen des Filters.
     *
     * Gesucht wird ueber den abgeleiteten Sortiernamen und die Referenznummer:
     * wer eine Nummer im Kopf hat, tippt sie ein, und wer einen Namen sucht,
     * soll ihn auch mit dem Vornamen finden.
     */
    private function restricted(PartyFilter $filter, string $select): QueryBuilder
    {
        $query = $this->entityManager->createQueryBuilder()
            ->select($select)
            ->from(Party::class, 'p');

        if (!$filter->withPast) {
            $query->andWhere('p.status <> :archived')->setParameter('archived', PartyStatus::Archived);
        }

        if (null !== $filter->role) {
            // Eingebettetes Objekt: der Pfad geht über die Eigenschaft.
            $query->andWhere('p.roles.value LIKE :role')
                ->setParameter('role', '%'.PartyRoles::marked($filter->role->value).'%');
        }

        if (null !== $filter->search) {
            $this->restrictToSearch($query, $filter->search);
        }

        return $query;
    }

    /**
     * Sucht ueber den Namen und, wenn die Eingabe eine Zahl ist, zusaetzlich
     * ueber die Referenznummer.
     *
     * Wer eine Nummer im Kopf hat, tippt sie ein; wer einen Namen sucht, soll
     * ihn auch ueber den Vornamen finden. Die Nummer wird dabei genau
     * verglichen — "100" soll nicht jede Nummer treffen, die eine 100 enthaelt.
     */
    private function restrictToSearch(QueryBuilder $query, string $search): void
    {
        $conditions = ['p.partyName.sortName LIKE :search'];
        $query->setParameter('search', LikePattern::containing($search));

        if (ctype_digit($search)) {
            $conditions[] = 'p.reference = :reference';
            $query->setParameter('reference', (int) $search);
        }

        $query->andWhere(implode(' OR ', $conditions));
    }
}
