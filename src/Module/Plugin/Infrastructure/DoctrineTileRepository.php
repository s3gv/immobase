<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Plugin\Infrastructure;

use App\Module\Plugin\Domain\Tile;
use App\Module\Plugin\Domain\TileRepository;
use App\Shared\Identity\Uuid;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Kacheln, abgelegt wie die Zustellungen: ueber die Verbindung, nicht ueber
 * die Arbeitseinheit.
 *
 * Sie kommen ueber die Schnittstelle herein und haben mit dem Lebenszyklus
 * einer Entitaet nichts zu tun. Als Entity waeren sie ausserdem im
 * Aenderungsprotokoll gelandet — und ein Protokoll, das jede Viertelstunde
 * „Kachel aktualisiert" sagt, ist nach einem Tag unlesbar.
 */
final readonly class DoctrineTileRepository implements TileRepository
{
    private const string TIME = 'Y-m-d H:i:s';

    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function replace(string $plugin, array $tiles): void
    {
        $connection = $this->entityManager->getConnection();

        $connection->transactional(static function () use ($connection, $plugin, $tiles): void {
            $connection->delete('plugin_tile', ['plugin' => $plugin]);

            foreach ($tiles as $tile) {
                $connection->insert('plugin_tile', [
                    'id' => Uuid::v4(),
                    'plugin' => $tile->plugin,
                    'key' => $tile->key,
                    'labels' => json_encode($tile->labels, \JSON_THROW_ON_ERROR),
                    'value' => $tile->value,
                    'tone' => $tile->tone,
                    'path' => $tile->path,
                    'seen_at' => $tile->seenAt->format(self::TIME),
                ]);
            }
        });
    }

    public function fresh(DateTimeImmutable $since): array
    {
        $rows = $this->entityManager->getConnection()->fetchAllAssociative(
            'SELECT plugin, key, labels, value, tone, path, seen_at
             FROM plugin_tile WHERE seen_at >= ? ORDER BY plugin, key',
            [$since->format(self::TIME)],
        );

        return array_values(array_map(self::hydrate(...), $rows));
    }

    public function forgetPlugin(string $plugin): void
    {
        $this->entityManager->getConnection()->delete('plugin_tile', ['plugin' => $plugin]);
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function hydrate(array $row): Tile
    {
        return new Tile(
            self::text($row, 'plugin'),
            self::text($row, 'key'),
            self::labels(self::text($row, 'labels')),
            self::text($row, 'value'),
            self::text($row, 'tone'),
            self::text($row, 'path'),
            new DateTimeImmutable(self::text($row, 'seen_at')),
        );
    }

    /**
     * @return array<string, string>
     */
    private static function labels(string $json): array
    {
        $decoded = json_decode($json, true);
        $labels = [];

        if (!\is_array($decoded)) {
            return [];
        }

        foreach ($decoded as $locale => $label) {
            if (\is_string($locale) && \is_string($label)) {
                $labels[$locale] = $label;
            }
        }

        return $labels;
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
