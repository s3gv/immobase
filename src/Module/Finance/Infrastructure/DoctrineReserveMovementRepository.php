<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Finance\Infrastructure;

use App\Module\Finance\Domain\AdvanceKind;
use App\Module\Finance\Domain\AdvancePayment;
use App\Module\Finance\Domain\ReserveBalance;
use App\Module\Finance\Domain\ReserveMovement;
use App\Module\Finance\Domain\ReserveMovementRepository;
use App\Module\Property\Contract\UnitDirectory;
use App\Shared\Ui\Page;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Das Ruecklagenkonto — gebucht und abgeleitet.
 *
 * Zwei Quellen, eine Rechnung: die gebuchten Bewegungen stehen in der
 * Datenbank, die Sonderumlagen stehen an den Zahlungen der Einheiten. Beide
 * zusammen ergeben den Bestand.
 *
 * Warum die Sonderumlagen nicht mitgebucht werden: der Beschluss stellt sie
 * faellig, aber auf dem Konto liegt nur, was auch ankam. Wer eine Rate auf
 * „nicht gezahlt" stellt, senkt damit den Bestand — und muesste sonst daran
 * denken, die Buchung nachzuziehen.
 */
final readonly class DoctrineReserveMovementRepository implements ReserveMovementRepository
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private UnitDirectory $units,
    ) {
    }

    public function save(ReserveMovement $movement): void
    {
        $this->entityManager->persist($movement);
        $this->entityManager->flush();
    }

    public function byId(string $id): ?ReserveMovement
    {
        return $this->entityManager->getRepository(ReserveMovement::class)->find($id);
    }

    public function countAll(): int
    {
        $count = $this->entityManager->createQueryBuilder()
            ->select('COUNT(m.id)')
            ->from(ReserveMovement::class, 'm')
            ->getQuery()
            ->getSingleScalarResult();

        return is_numeric($count) ? (int) $count : 0;
    }

    public function pageOf(Page $page): array
    {
        /** @var list<ReserveMovement> $movements */
        $movements = $this->entityManager->createQueryBuilder()
            ->select('m')
            ->from(ReserveMovement::class, 'm')
            ->orderBy('m.occurredOn', 'DESC')
            ->addOrderBy('m.id', 'ASC')
            ->setFirstResult($page->offset())
            ->setMaxResults($page->limit())
            ->getQuery()
            ->getResult();

        return $movements;
    }

    public function forProperties(array $propertyIds): array
    {
        if ([] === $propertyIds) {
            return [];
        }

        /** @var list<ReserveMovement> $movements */
        $movements = $this->entityManager->createQueryBuilder()
            ->select('m')
            ->from(ReserveMovement::class, 'm')
            ->where('m.propertyId IN (:properties)')
            ->setParameter('properties', $propertyIds, ArrayParameterType::STRING)
            ->orderBy('m.occurredOn', 'DESC')
            ->getQuery()
            ->getResult();

        $byProperty = [];

        foreach ($propertyIds as $propertyId) {
            $byProperty[$propertyId] = [];
        }

        foreach ([...$movements, ...$this->levies()] as $movement) {
            if (\array_key_exists($movement->propertyId(), $byProperty)) {
                $byProperty[$movement->propertyId()][] = $movement;
            }
        }

        return array_map(ReserveBalance::of(...), array_filter($byProperty));
    }

    /**
     * Die Sonderumlagen, wie sie ankamen.
     *
     * Der Tag ist die Faelligkeit und nicht der Zahlungseingang: ein
     * Eingangsdatum haben wir nicht, und die Faelligkeit ist der Tag, den der
     * Beschluss nennt. Was gar nicht ankam, steht nicht auf dem Konto —
     * es steht als Forderung im Vermoegensbericht.
     *
     * @return list<ReserveMovement>
     */
    private function levies(): array
    {
        $paid = $this->paidLevies();

        if ([] === $paid) {
            return [];
        }

        $units = $this->units->byIds(array_values(array_unique(
            array_map(static fn (AdvancePayment $payment): string => $payment->unitId(), $paid),
        )));
        $found = [];

        foreach ($paid as $payment) {
            $unit = $units[$payment->unitId()] ?? null;

            if (null !== $unit && !$payment->received()->isZero()) {
                $found[] = ReserveMovement::fromALevy(
                    $unit->propertyId,
                    $payment->unitId(),
                    $payment->dueOn(),
                    $payment->received(),
                );
            }
        }

        return $found;
    }

    /**
     * @return list<AdvancePayment>
     */
    private function paidLevies(): array
    {
        /** @var list<AdvancePayment> $paid */
        $paid = $this->entityManager->createQueryBuilder()
            ->select('p')
            ->from(AdvancePayment::class, 'p')
            ->where('p.kind = :kind')
            ->setParameter('kind', AdvanceKind::ReserveLevy->value)
            ->orderBy('p.dueOn', 'DESC')
            ->getQuery()
            ->getResult();

        return $paid;
    }
}
