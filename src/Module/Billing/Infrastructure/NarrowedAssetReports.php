<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Billing\Infrastructure;

use App\Module\Billing\Domain\AssetReport;
use App\Module\Billing\Domain\AssetReportFilter;
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
final readonly class NarrowedAssetReports
{
    public static function of(EntityManagerInterface $manager, AssetReportFilter $filter): QueryBuilder
    {
        $query = $manager->createQueryBuilder()->from(AssetReport::class, 'r');

        if (null !== $filter->propertyNumber) {
            $query->andWhere('r.propertyNumber = :property')->setParameter('property', $filter->propertyNumber);
        }

        if (null !== $filter->fiscalYear) {
            $query->andWhere('r.period.year = :year')->setParameter('year', $filter->fiscalYear);
        }

        if (null !== $filter->status) {
            $query->andWhere('r.release.status = :status')->setParameter('status', $filter->status->value);
        }

        if (null !== $filter->search) {
            $query->andWhere('LOWER(r.label) LIKE :search')->setParameter('search', LikePattern::containing($filter->search));
        }

        return $query;
    }
}
