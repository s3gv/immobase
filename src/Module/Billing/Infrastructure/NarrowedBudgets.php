<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Billing\Infrastructure;

use App\Module\Billing\Domain\Budget;
use App\Module\Billing\Domain\BudgetFilter;
use App\Shared\Search\LikePattern;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;

/**
 * Der Filter, in eine Abfrage uebersetzt.
 *
 * Einmal geschrieben und zweimal benutzt — von der Zaehlung und von der Seite.
 * Zwei Stellen liefen frueher oder spaeter auseinander, und dann stuende ueber
 * einer Liste eine Zahl, die nicht zu ihr gehoert.
 */
final readonly class NarrowedBudgets
{
    public static function of(EntityManagerInterface $manager, BudgetFilter $filter): QueryBuilder
    {
        $query = $manager->createQueryBuilder()->from(Budget::class, 'b');

        if (null !== $filter->propertyNumber) {
            $query->andWhere('b.propertyNumber = :property')->setParameter('property', $filter->propertyNumber);
        }

        if (null !== $filter->firstYear) {
            $query->andWhere('b.measure.firstYear = :year')->setParameter('year', $filter->firstYear);
        }

        if (null !== $filter->status) {
            $query->andWhere('b.stage.status = :status')->setParameter('status', $filter->status->value);
        }

        if (null !== $filter->search) {
            $query->andWhere('LOWER(b.measure.label) LIKE :search')->setParameter('search', LikePattern::containing($filter->search));
        }

        return $query;
    }
}
