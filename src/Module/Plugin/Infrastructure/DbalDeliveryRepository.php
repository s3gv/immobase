<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Plugin\Infrastructure;

use App\Module\Plugin\Domain\Delivery;
use App\Module\Plugin\Domain\DeliveryRepository;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Die Ausgangsablage, an der Arbeitseinheit vorbei.
 *
 * **Aus demselben Grund wie beim Protokoll.** Die Zeilen entstehen waehrend
 * eines `flush()`; ein zweites `flush()` von dort aus faehrt Doctrine in
 * denselben Vorgang hinein, waehrend er noch laeuft. Dieselbe Verbindung
 * genuegt — und damit faellt eine Zustellung mit zurueck, wenn der Vorgang,
 * den sie meldet, zurueckgenommen wird. Das ist richtig so: was nicht
 * geschehen ist, wird auch nicht gemeldet.
 */
final readonly class DbalDeliveryRepository implements DeliveryRepository
{
    /**
     * Nach so vielen Versuchen gilt eine Zustellung als aufgegeben.
     *
     * Sie bleibt danach mit ihrem Vermerk stehen, damit jemand nachsehen
     * kann, was schiefging — und verfaellt nach einer Frist. Ein Stapel, der
     * ewig waechst, weil ein Plugin seit Monaten aus ist, waere das
     * Gegenteil von „ein Plugin traegt nicht".
     */
    public const int TRIES = 5;

    private const string TIME = 'Y-m-d H:i:s';

    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function append(array $deliveries): void
    {
        $connection = $this->entityManager->getConnection();

        foreach ($deliveries as $delivery) {
            $connection->insert('plugin_delivery', [
                'id' => $delivery->id,
                'plugin' => $delivery->plugin,
                'event' => $delivery->event,
                'payload' => $delivery->payload,
                'created_at' => $delivery->createdAt->format(self::TIME),
                'deliver_at' => $delivery->deliverAt->format(self::TIME),
                'attempts' => $delivery->attempts,
                'last_error' => $delivery->lastError,
            ]);
        }
    }

    public function due(DateTimeImmutable $now, int $limit): array
    {
        $rows = $this->entityManager->getConnection()->fetchAllAssociative(
            'SELECT id, plugin, event, payload, created_at, deliver_at, attempts, last_error
             FROM plugin_delivery
             WHERE deliver_at <= ? AND attempts < ?
             ORDER BY created_at
             LIMIT '.$limit,
            [$now->format(self::TIME), self::TRIES],
        );

        return array_values(array_map(self::hydrate(...), $rows));
    }

    public function forget(string $id): void
    {
        $this->entityManager->getConnection()->delete('plugin_delivery', ['id' => $id]);
    }

    public function retryLater(string $id, DateTimeImmutable $at, string $error): void
    {
        $this->entityManager->getConnection()->executeStatement(
            'UPDATE plugin_delivery SET attempts = attempts + 1, deliver_at = ?, last_error = ? WHERE id = ?',
            [$at->format(self::TIME), mb_substr($error, 0, 300), $id],
        );
    }

    public function giveUp(string $id, string $error): void
    {
        $this->entityManager->getConnection()->executeStatement(
            'UPDATE plugin_delivery SET attempts = ?, last_error = ? WHERE id = ?',
            [self::TRIES, mb_substr($error, 0, 300), $id],
        );
    }

    public function forgetPlugin(string $plugin): void
    {
        $this->entityManager->getConnection()->delete('plugin_delivery', ['plugin' => $plugin]);
    }

    public function sweep(DateTimeImmutable $before): int
    {
        return (int) $this->entityManager->getConnection()->executeStatement(
            'DELETE FROM plugin_delivery WHERE attempts >= ? AND created_at < ?',
            [self::TRIES, $before->format(self::TIME)],
        );
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function hydrate(array $row): Delivery
    {
        return new Delivery(
            self::text($row, 'id'),
            self::text($row, 'plugin'),
            self::text($row, 'event'),
            self::text($row, 'payload'),
            new DateTimeImmutable(self::text($row, 'created_at')),
            new DateTimeImmutable(self::text($row, 'deliver_at')),
            (int) self::text($row, 'attempts'),
            self::text($row, 'last_error'),
        );
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function text(array $row, string $column): string
    {
        $value = $row[$column] ?? '';

        return \is_scalar($value) ? (string) $value : '';
    }
}
