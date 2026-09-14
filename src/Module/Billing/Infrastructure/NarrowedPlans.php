<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Billing\Infrastructure;

use App\Module\Billing\Domain\Plan;
use App\Module\Billing\Domain\PlanFilter;
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
final readonly class NarrowedPlans
{
    public static function of(EntityManagerInterface $manager, PlanFilter $filter): QueryBuilder
    {
        $query = $manager->createQueryBuilder()->from(Plan::class, 'p');

        if (null !== $filter->propertyNumber) {
            $query->andWhere('p.propertyNumber = :property')->setParameter('property', $filter->propertyNumber);
        }

        if (null !== $filter->fiscalYear) {
            $query->andWhere('p.period.year = :year')->setParameter('year', $filter->fiscalYear);
        }

        if (null !== $filter->status) {
            $query->andWhere('p.stage.status = :status')->setParameter('status', $filter->status->value);
        }

        if (null !== $filter->search) {
            $query->andWhere('LOWER(p.label) LIKE :search')->setParameter('search', LikePattern::containing($filter->search));
        }

        return $query;
    }
}
