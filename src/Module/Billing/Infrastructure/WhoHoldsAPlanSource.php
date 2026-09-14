<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Billing\Infrastructure;

use App\Module\Billing\Domain\Plan;
use App\Module\Billing\Domain\PlanSource;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Welcher Wirtschaftsplan eine Quelle festhaelt.
 *
 * Die Finanzen fragen das, bevor sie eine Kostenart oder einen eigenen
 * Verteilerschluessel loeschen. Die Antwort ist keine Kennung, sondern das,
 * was ein Mensch vorgelesen bekommt — „20001-2027-3".
 */
final readonly class WhoHoldsAPlanSource
{
    /**
     * @param list<string> $sourceIds
     *
     * @return array<string, string> Kennung der Quelle auf den Plan, der sie haelt
     */
    public static function among(EntityManagerInterface $manager, array $sourceIds): array
    {
        /** @var list<array{keyId: string|null, costKindId: string|null, propertyNumber: int, fiscalYear: int, number: int}> $rows */
        $rows = $manager->createQueryBuilder()
            ->select(
                'src.keyId AS keyId',
                'src.costKindId AS costKindId',
                'p.propertyNumber AS propertyNumber',
                'p.period.year AS fiscalYear',
                'p.edition.number AS number',
            )
            ->from(PlanSource::class, 'src')
            ->innerJoin(Plan::class, 'p', 'WITH', 'p.id = src.planId')
            ->where('src.keyId IN (:ids) OR src.costKindId IN (:ids)')
            ->setParameter('ids', $sourceIds)
            ->getQuery()
            ->getResult();

        $held = [];

        foreach ($rows as $row) {
            $id = $row['keyId'] ?? $row['costKindId'];

            if (null !== $id) {
                $held[$id] = \sprintf('%d-%d-%d', $row['propertyNumber'], $row['fiscalYear'], $row['number']);
            }
        }

        return $held;
    }
}
