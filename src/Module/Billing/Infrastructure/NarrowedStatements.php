<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Billing\Infrastructure;

use App\Module\Billing\Domain\Statement;
use App\Module\Billing\Domain\StatementFilter;
use App\Shared\Search\LikePattern;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;

/**
 * Der Filter, in eine Abfrage uebersetzt.
 *
 * Steht neben dem Repository und nicht darin: das Uebersetzen ist eine
 * eigene Aufgabe, und das Repository ist sonst voll davon.
 */
final readonly class NarrowedStatements
{
    /**
     * Die Abfrage, um den Filter beschnitten.
     *
     * Einmal geschrieben und zweimal benutzt — von der Zaehlung und von der
     * Seite. Zwei Stellen liefen frueher oder spaeter auseinander, und dann
     * stuende ueber einer Liste eine Zahl, die nicht zu ihr gehoert.
     */
    public static function of(EntityManagerInterface $manager, StatementFilter $filter): QueryBuilder
    {
        $query = $manager->createQueryBuilder()->from(Statement::class, 's');

        if (null !== $filter->propertyNumber) {
            $query->andWhere('s.propertyNumber = :property')->setParameter('property', $filter->propertyNumber);
        }

        if (null !== $filter->fiscalYear) {
            $query->andWhere('s.period.year = :year')->setParameter('year', $filter->fiscalYear);
        }

        if (null !== $filter->status) {
            $query->andWhere('s.release.status = :status')->setParameter('status', $filter->status->value);
        }

        if (null !== $filter->search) {
            $query->andWhere('LOWER(s.label) LIKE :search')->setParameter('search', LikePattern::containing($filter->search));
        }

        return $query;
    }
}
