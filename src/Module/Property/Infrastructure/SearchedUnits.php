<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Property\Infrastructure;

use App\Module\Property\Domain\Unit;
use App\Shared\Search\SearchTerm;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;

/**
 * Die Abfrage der zentralen Suche ueber die Einheiten.
 *
 * Ohne die Einschraenkung auf verwaltete Objekte, die die Vervollstaendigung
 * setzt: wer eine abgegebene Einheit sucht, sucht sie, weil er sie meint. In
 * einer Auswahlliste waere sie falsch — dort wird etwas gewaehlt, mit dem es
 * weitergehen soll.
 */
final readonly class SearchedUnits
{
    private const array CONDITIONS = [
        'LOWER(u.label) LIKE :text',
        'LOWER(p.name) LIKE :text',
        'LOWER(p.address.street) LIKE :text',
        'LOWER(p.address.city) LIKE :text',
    ];

    public static function of(EntityManagerInterface $manager, SearchTerm $term, int $limit): QueryBuilder
    {
        $query = $manager->createQueryBuilder()
            ->select('u', 'p')
            ->from(Unit::class, 'u')
            ->join('u.property', 'p')
            ->orderBy('u.management.status', 'ASC')
            ->addOrderBy('p.number', 'ASC')
            ->addOrderBy('u.number', 'ASC')
            ->setMaxResults($limit)
            ->setParameter('text', $term->contains());

        $conditions = self::CONDITIONS;

        // Objektnummer und Nummer der Einheit sind beide gemeint: „3" kann
        // das dritte Objekt sein oder die dritte Einheit, und beides zu
        // zeigen ist besser, als eines davon zu raten.
        if ($term->isNumber()) {
            $conditions[] = 'p.number = :number';
            $conditions[] = 'u.number = :number';
            $query->setParameter('number', $term->number());
        }

        return $query->andWhere(implode(' OR ', $conditions));
    }
}
