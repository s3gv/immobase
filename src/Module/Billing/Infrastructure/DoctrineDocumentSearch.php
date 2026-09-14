<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Billing\Infrastructure;

use App\Module\Billing\Domain\AssetReport;
use App\Module\Billing\Domain\Budget;
use App\Module\Billing\Domain\DocumentHit;
use App\Module\Billing\Domain\DocumentKind;
use App\Module\Billing\Domain\DocumentSearch;
use App\Module\Billing\Domain\Plan;
use App\Module\Billing\Domain\Statement;
use App\Shared\Search\SearchTerm;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Die Schreiben dieses Moduls, in einer Liste gesucht.
 *
 * Je Art eine Abfrage, und alle in derselben Form: Bezeichnung,
 * Objektnummer, Jahr. Sie unterscheiden sich nur darin, wo das Jahr steht —
 * beim Budgetplan im Beschluss, bei den uebrigen im Abrechnungszeitraum.
 *
 * Geholt werden Felder und keine Entitaeten: vier Tabellen samt ihren
 * Einbettungen zu laden, um drei Spalten zu zeigen, waere Arbeit fuer nichts.
 *
 * Sortiert wird ueber alle nach Jahr, das juengste zuerst. Wer ein
 * Schreiben sucht, sucht fast immer das letzte.
 */
final readonly class DoctrineDocumentSearch implements DocumentSearch
{
    /**
     * Art, Entitaet, Feld der Bezeichnung, Feld des Jahres.
     *
     * **Ohne die Dauermietrechnung.** Sie traegt weder Objektnummer noch
     * Jahr — sie haengt an einem Mietverhaeltnis, und ueber das wird sie
     * gefunden. Sie hier mitzufuehren hiesse, fuer eine Art eine zweite
     * Abfrageform zu bauen, die etwas anderes bedeutet als die vier anderen.
     */
    private const array SOURCES = [
        [DocumentKind::Statement, Statement::class, 'd.label', 'd.period.year'],
        [DocumentKind::Plan, Plan::class, 'd.label', 'd.period.year'],
        [DocumentKind::AssetReport, AssetReport::class, 'd.label', 'd.period.year'],
        [DocumentKind::Budget, Budget::class, 'd.measure.label', 'd.measure.firstYear'],
    ];

    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function anywhere(SearchTerm $term, int $limit): array
    {
        $hits = [];

        foreach (self::SOURCES as [$kind, $entity, $label, $year]) {
            foreach ($this->rowsOf($entity, $label, $year, $term, $limit) as $row) {
                $hits[] = new DocumentHit(
                    id: $row['id'],
                    kind: $kind,
                    label: $row['label'],
                    propertyNumber: $row['propertyNumber'],
                    year: $row['year'],
                );
            }
        }

        usort($hits, static fn (DocumentHit $a, DocumentHit $b): int => $b->year <=> $a->year);

        return \array_slice($hits, 0, $limit);
    }

    /**
     * @param class-string $entity
     *
     * @return list<array{id: string, label: string, propertyNumber: int, year: int}>
     */
    private function rowsOf(string $entity, string $label, string $year, SearchTerm $term, int $limit): array
    {
        $query = $this->entityManager->createQueryBuilder()
            ->select('d.id AS id', $label.' AS label', 'd.propertyNumber AS propertyNumber', $year.' AS year')
            ->from($entity, 'd')
            ->orderBy($year, 'DESC')
            ->setMaxResults($limit);

        $conditions = ['LOWER('.$label.') LIKE :text'];
        $query->setParameter('text', $term->contains());

        // Die Nummer eines Objekts und eine Jahreszahl sehen gleich aus. Beide
        // zu pruefen ist billiger, als zu raten: „2026" trifft dann das Jahr,
        // „20001" das Objekt, und keines von beiden trifft das andere.
        if ($term->isNumber()) {
            $conditions[] = 'd.propertyNumber = :number';
            $conditions[] = $year.' = :number';
            $query->setParameter('number', $term->number());
        }

        /** @var list<array{id: string, label: string, propertyNumber: int, year: int}> $rows */
        $rows = $query->andWhere(implode(' OR ', $conditions))->getQuery()->getArrayResult();

        return $rows;
    }
}
