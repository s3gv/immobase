<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Billing\Infrastructure;

use App\Module\Billing\Domain\Statement;
use App\Module\Billing\Domain\StatementSource;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Welche Abrechnung eine Quelle festhaelt.
 *
 * Die Finanzen fragen das, bevor sie einen Jahreswert oder eine Zahlung
 * loeschen: steht sie in einer Abrechnung, bleibt sie. Die Antwort ist keine
 * Kennung, sondern das, was ein Mensch vorgelesen bekommt — „20001-2026-3".
 *
 * Eine eigene Klasse, weil es eine eigene Frage ist: alles andere am
 * Repository dreht sich um einen Lauf, diese eine um eine fremde Kennung.
 */
final readonly class WhoHoldsASource
{
    /**
     * @param list<string> $sourceIds
     *
     * @return array<string, string> Kennung der Quelle auf die Abrechnung, die sie haelt
     */
    public static function among(EntityManagerInterface $manager, array $sourceIds): array
    {
        /** @var list<array{sourceId: string|null, paymentId: string|null, propertyNumber: int, fiscalYear: int, number: int}> $rows */
        $rows = $manager->createQueryBuilder()
            ->select(
                'src.costYearId AS sourceId',
                'src.paymentId AS paymentId',
                's.propertyNumber AS propertyNumber',
                's.period.year AS fiscalYear',
                's.number AS number',
            )
            ->from(StatementSource::class, 'src')
            ->innerJoin(Statement::class, 's', 'WITH', 's.id = src.statementId')
            ->where('src.costYearId IN (:ids) OR src.paymentId IN (:ids)')
            ->setParameter('ids', $sourceIds)
            ->getQuery()
            ->getResult();

        $held = [];

        foreach ($rows as $row) {
            $id = $row['sourceId'] ?? $row['paymentId'];

            if (null !== $id) {
                $held[$id] = \sprintf('%d-%d-%d', $row['propertyNumber'], $row['fiscalYear'], $row['number']);
            }
        }

        return $held;
    }
}
