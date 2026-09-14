<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Tenancy\Infrastructure;

use App\Module\Tenancy\Domain\RentStep;
use App\Module\Tenancy\Domain\Tenancy;
use App\Module\Tenancy\Domain\TenancyFilter;
use App\Module\Tenancy\Domain\TenancyStatus;
use App\Module\Tenancy\Domain\Tenant;
use App\Shared\Ui\Sort;
use DateTimeImmutable;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;

/**
 * Die Abfragen der Mietuebersicht: filtern und sortieren.
 *
 * Getrennt vom Repository, weil es zwei Dinge sind. Das Repository legt ab
 * und holt; hier steht, wie aus Adresszeile und Filter eine Abfrage wird —
 * mitsamt der Unterabfrage fuer die heute geltende Gesamtmiete, die
 * mehr Erklaerung braucht als der Rest zusammen.
 */
final readonly class TenancyQueries
{
    /**
     * Was sich sortieren laesst — und wie es in der Abfrage heisst.
     *
     * Die Liste steht hier, weil sie Feldnamen sind und so in die Abfrage
     * gehen. Was nicht darin steht, kommt nicht durch; `Sort` prueft dagegen.
     */
    public const array SORTABLE = [
        'nummer' => 't.number',
        'beginn' => 't.term.startsOn',
        'ende' => 't.term.endsOn',
        'status' => 't.status',
        'miete' => 'total',
    ];

    /**
     * Die heute geltende Gesamtmiete als Sortierschluessel.
     *
     * Ueber eine Unterabfrage und nicht ueber einen mitgefuehrten Zwischenwert:
     * eine zweite Wahrheit neben der Staffel liefe frueher oder spaeter
     * auseinander — dieselbe Falle wie die Einheiten-Zaehlfelder im
     * Vorgaengersystem.
     *
     * Die innere Abfrage sucht die letzte Stufe mit Datum <= heute, die
     * aeussere summiert ihre vier Positionen. Ohne Stufe kommt null heraus,
     * und die Zeile steht am Rand der Sortierung — was richtig ist: „keine
     * Miete erfasst" ist kein Betrag.
     */
    private const string CURRENT_TOTAL = <<<'DQL'
        (SELECT SUM(r.base + r.operatingCosts + r.heating + r.parking)
         FROM %s r
         WHERE r.tenancy = t
           AND r.startsOn = (
               SELECT MAX(r2.startsOn) FROM %s r2
               WHERE r2.tenancy = t AND r2.startsOn <= :today
           ))
        DQL;

    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    /**
     * Die gefilterte Abfrage — `$select` entscheidet, ob gezaehlt oder
     * geladen wird.
     */
    public function restricted(TenancyFilter $filter, string $select): QueryBuilder
    {
        $query = $this->entityManager->createQueryBuilder()
            ->select($select)
            ->from(Tenancy::class, 't');

        if (null !== $filter->status) {
            $query->andWhere('t.status = :status')->setParameter('status', $filter->status->value);
        } elseif (!$filter->withPast) {
            // Beendete stehen bereit, aber nicht im Weg.
            $query->andWhere('t.status <> :ended')->setParameter('ended', TenancyStatus::Ended->value);
        }

        // Der Objektfilter grenzt ein und steht deshalb fuer sich.
        if (null !== $filter->withinUnits) {
            $query->andWhere('t.unitId IN (:within)')
                ->setParameter('within', $filter->withinUnits, ArrayParameterType::STRING);
        }

        if ($filter->searching) {
            self::restrictToSearch($query, $filter);
        }

        return $query;
    }

    /**
     * Die Sortierung anlegen.
     *
     * Ein zweiter Schluessel muss sein, damit gleiche Werte nicht in
     * wechselnder Reihenfolge stehen — sonst springen Zeilen beim Blaettern.
     */
    public function sorted(QueryBuilder $query, Sort $sort): QueryBuilder
    {
        // Die Unterabfrage nur, wenn danach sortiert wird: sie kostet, und
        // die Uebersicht rechnet die Summe ohnehin aus der geladenen Staffel.
        if ('miete' === $sort->field) {
            $query
                ->addSelect(\sprintf(self::CURRENT_TOTAL, RentStep::class, RentStep::class).' AS HIDDEN total')
                ->setParameter('today', new DateTimeImmutable('today'));
        }

        return $query
            ->orderBy(self::SORTABLE[$sort->field] ?? 't.number', $sort->sql())
            ->addOrderBy('t.number', 'ASC');
    }

    /**
     * Die Mietverhaeltnisse zu diesen Kennungen.
     *
     * @param list<string> $ids
     */
    public function withIds(array $ids): QueryBuilder
    {
        return $this->entityManager->createQueryBuilder()
            ->select('t')
            ->from(Tenancy::class, 't')
            ->where('t.id IN (:ids)')
            ->setParameter('ids', $ids);
    }

    /**
     * Die laufenden Mietverhaeltnisse, die bis zu diesem Tag enden.
     *
     * Nur die laufenden: ein Entwurf endet nicht, und ein beendetes
     * Mietverhaeltnis ist schon abgearbeitet.
     */
    public function endingBy(DateTimeImmutable $day): QueryBuilder
    {
        return $this->entityManager->createQueryBuilder()
            ->select('COUNT(t.id)')
            ->from(Tenancy::class, 't')
            ->where('t.status = :active')
            ->andWhere('t.term.endsOn IS NOT NULL')
            ->andWhere('t.term.endsOn <= :day')
            ->setParameter('active', TenancyStatus::Active->value)
            ->setParameter('day', $day);
    }

    /**
     * Zwei Zeitraeume ueberschneiden sich, wenn keiner vor dem anderen endet.
     *
     * Ohne Beginn ist keine Ueberschneidung feststellbar; ohne Ende reicht
     * eine Laufzeit bis in alle Zukunft und trifft alles, was danach kommt.
     */
    public function onlyWithin(QueryBuilder $query, DateTimeImmutable $from, ?DateTimeImmutable $to): void
    {
        $query
            ->andWhere('t.term.startsOn IS NOT NULL')
            ->andWhere('t.term.endsOn IS NULL OR t.term.endsOn >= :from')
            ->setParameter('from', $from);

        if (null !== $to) {
            $query->andWhere('t.term.startsOn <= :to')->setParameter('to', $to);
        }
    }

    /**
     * Die Suche trifft breit: Nummer, Einheit oder Mieter.
     *
     * Was davon passt, ist eine Oder-Frage — wer „Rosenweg" tippt, sucht die
     * Mietverhaeltnisse dieses Hauses, wer „Muster" tippt, die dieser Person.
     * Passt nichts, bleibt die Bedingung unerfuellbar und die Liste leer; das
     * ist die richtige Antwort und nicht „alles".
     */
    private static function restrictToSearch(QueryBuilder $query, TenancyFilter $filter): void
    {
        $conditions = [];

        if (null !== $filter->number) {
            $conditions[] = 't.number = :number';
            $query->setParameter('number', $filter->number);
        }

        if ([] !== $filter->matchingUnits) {
            $conditions[] = 't.unitId IN (:units)';
            $query->setParameter('units', $filter->matchingUnits, ArrayParameterType::STRING);
        }

        if ([] !== $filter->matchingParties) {
            $conditions[] = 'EXISTS (SELECT 1 FROM '.Tenant::class.' m WHERE m.tenancy = t AND m.partyId IN (:parties))';
            $query->setParameter('parties', $filter->matchingParties, ArrayParameterType::STRING);
        }

        $query->andWhere([] === $conditions ? '1 = 0' : implode(' OR ', $conditions));
    }
}
