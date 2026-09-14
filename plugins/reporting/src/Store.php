<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace Reporting;

use DateTimeImmutable;
use PDO;

/**
 * Der eigene Speicher — ein Schema in der Datenbank des Cores.
 *
 * **Die Tabellen stehen im Manifest, nicht hier.** Der Core legt sie beim
 * Aktivieren an; dieses Plugin kann sie weder anlegen noch aendern, und an
 * die Tabellen des Cores kommt es nicht. Fachdaten gibt es ueber `/api/v1/`
 * und sonst nirgends.
 *
 * Der Spiegel ist Zwischenstand und keine Wahrheit: wenn er verlorenginge,
 * liesse er sich aus dem Core neu aufbauen.
 */
final class Store implements MirrorStorage
{
    /**
     * Eine beliebige, aber feste Zahl fuer die Sperre der Spiegelung.
     *
     * Beratende Sperren gelten datenbankweit; jedes Plugin, das eine benutzt,
     * sollte seine eigene Zahl haben.
     */
    private const int MIRROR_LOCK = 7_300_100;

    private ?PDO $connection = null;

    public function __construct(private readonly Core $core)
    {
    }

    public function connection(): PDO
    {
        if (null !== $this->connection) {
            return $this->connection;
        }

        $parts = parse_url($this->core->storageDsn());
        $dsn = \sprintf(
            'pgsql:host=%s;port=%d;dbname=%s;options=--search_path=%s',
            $parts['host'] ?? 'database',
            $parts['port'] ?? 5432,
            ltrim($parts['path'] ?? '', '/'),
            rawurldecode((string) ($parts['user'] ?? '')),
        );

        return $this->connection = new PDO($dsn, rawurldecode((string) ($parts['user'] ?? '')), rawurldecode((string) ($parts['pass'] ?? '')), [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
    }

    /**
     * Eine Zeile setzen — vorhandene wird ueberschrieben.
     *
     * @param array<string, scalar|null> $values
     */
    public function put(string $table, array $values): void
    {
        $columns = array_keys($values);
        $names = implode(', ', $columns);
        $slots = implode(', ', array_map(static fn (string $column): string => ':'.$column, $columns));
        $updates = implode(', ', array_map(
            static fn (string $column): string => $column.' = EXCLUDED.'.$column,
            \array_slice($columns, 1),
        ));

        $this->connection()
            ->prepare(\sprintf('INSERT INTO %s (%s) VALUES (%s) ON CONFLICT (%s) DO UPDATE SET %s', $table, $names, $slots, $columns[0], $updates))
            ->execute(array_map(self::bindable(...), $values));
    }

    /**
     * PDO bindet `false` als leere Zeichenkette.
     *
     * PostgreSQL nimmt die fuer eine `boolean`-Spalte nicht an und bricht mit
     * „invalid input syntax" ab — an einer Stelle uebersetzt, statt an jedem
     * Aufrufer daran zu denken.
     */
    private static function bindable(mixed $value): mixed
    {
        return \is_bool($value) ? ($value ? 'true' : 'false') : $value;
    }

    /**
     * Allein arbeiten — ueber alle Prozesse des Plugins hinweg.
     *
     * Eine beratende Sperre der Datenbank, gehalten bis die Arbeit fertig ist,
     * auch wenn sie scheitert. Sie gilt fuer die Sitzung und nicht fuer eine
     * Transaktion: die Spiegelung fragt zwischendurch den Core, und so lange
     * soll keine Transaktion offen stehen.
     */
    public function exclusively(callable $work): bool
    {
        $connection = $this->connection();
        $connection->exec('SELECT pg_advisory_lock('.self::MIRROR_LOCK.')');

        try {
            return $work();
        } finally {
            $connection->exec('SELECT pg_advisory_unlock('.self::MIRROR_LOCK.')');
        }
    }

    /**
     * Den ganzen Spiegel auf einmal austauschen.
     *
     * **In einer Transaktion.** Wer die Berichte waehrenddessen liest, sieht
     * den alten Stand, bis alles geschrieben ist — nie eine geleerte Tabelle
     * neben einer schon gefuellten.
     */
    public function replaceAll(array $tables, DateTimeImmutable $at): void
    {
        $connection = $this->connection();
        $connection->beginTransaction();

        try {
            foreach ($tables as $table => $rows) {
                $connection->exec('DELETE FROM '.$table);

                foreach ($rows as $row) {
                    $this->put($table, $row);
                }
            }

            $this->noteSync('full', $at);
            $connection->commit();
        } catch (\Throwable $failed) {
            $connection->rollBack();

            throw $failed;
        }
    }

    /**
     * @param array<string, scalar|null> $parameters
     *
     * @return list<array<string, mixed>>
     */
    public function rows(string $sql, array $parameters = []): array
    {
        $statement = $this->connection()->prepare($sql);
        $statement->execute($parameters);

        /** @var list<array<string, mixed>> $rows */
        $rows = $statement->fetchAll();

        return $rows;
    }

    public function noteSync(string $name, DateTimeImmutable $at): void
    {
        $this->put('sync', ['name' => $name, 'at' => $at->format('Y-m-d H:i:s')]);
    }

    public function lastSync(string $name): ?DateTimeImmutable
    {
        $rows = $this->rows('SELECT at FROM sync WHERE name = :name', ['name' => $name]);
        $at = $rows[0]['at'] ?? null;

        return \is_string($at) ? new DateTimeImmutable($at) : null;
    }
}
