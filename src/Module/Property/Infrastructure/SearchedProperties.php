<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Property\Infrastructure;

use App\Module\Property\Domain\Property;
use App\Shared\Search\SearchTerm;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;

/**
 * Die Abfrage der zentralen Suche ueber die Objekte.
 *
 * Steht neben dem Repository und nicht darin: das Uebersetzen einer Frage in
 * eine Abfrage ist eine eigene Aufgabe.
 *
 * Anders als bei den Stammdaten reicht hier DQL: an einem Objekt steht alles
 * in eigenen Spalten, nichts liegt als JSON in der Zeile.
 */
final readonly class SearchedProperties
{
    /**
     * Ueber die zusammengezogene Schreibweise wird die Bankverbindung
     * verglichen: gespeichert steht die IBAN am Stueck, getippt wird sie in
     * Vierergruppen.
     */
    private const array CONDITIONS = [
        'LOWER(p.name) LIKE :text',
        'LOWER(p.address.street) LIKE :text',
        'LOWER(p.address.postalCode) LIKE :text',
        'LOWER(p.address.city) LIKE :text',
        'LOWER(p.accounting.account.holder) LIKE :text',
        'LOWER(p.accounting.account.label) LIKE :text',
        'UPPER(p.accounting.account.iban) LIKE :compact',
        'UPPER(p.accounting.account.bic) LIKE :compact',
    ];

    public static function of(EntityManagerInterface $manager, SearchTerm $term, int $limit): QueryBuilder
    {
        $query = $manager->createQueryBuilder()
            ->select('p')
            ->from(Property::class, 'p')
            ->orderBy('p.management.status', 'ASC')
            ->addOrderBy('p.number', 'ASC')
            ->setMaxResults($limit)
            ->setParameter('text', $term->contains())
            ->setParameter('compact', '%'.$term->compact().'%');

        $conditions = self::CONDITIONS;

        if ($term->isNumber()) {
            $conditions[] = 'p.number = :number';
            $query->setParameter('number', $term->number());
        }

        return $query->andWhere(implode(' OR ', $conditions));
    }
}
