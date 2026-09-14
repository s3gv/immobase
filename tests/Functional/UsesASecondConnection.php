<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Tests\Functional;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Eine zweite, eigene Verbindung zur selben Datenbank.
 *
 * Nur damit laesst sich ein Wettrennen ueberhaupt zeigen: auf einer
 * Verbindung sieht jede Anweisung, was die vorige getan hat, und eine Sperre
 * sperrt einen nie gegen sich selbst. Die zweite Verbindung haelt im Test
 * fest, was im Betrieb eine gleichzeitige Anfrage haelt.
 */
trait UsesASecondConnection
{
    /** Wie lange die wartende Seite es versucht, bevor der Test aufgibt. */
    private const string LOCK_TIMEOUT = '250ms';

    private ?Connection $other = null;

    /**
     * Die Angaben werden einzeln herausgezogen und nicht durchgereicht: die
     * Parameter einer bestehenden Verbindung sind fuer die statische Analyse
     * ein loses Array, und ein unterdrueckter Fehler waere hier der falsche
     * Weg.
     */
    protected function secondConnection(): Connection
    {
        /** @var array<string, mixed> $params */
        $params = self::entityManager()->getConnection()->getParams();

        return $this->other = DriverManager::getConnection([
            'driver' => 'pdo_pgsql',
            'host' => self::text($params, 'host'),
            'port' => self::port($params),
            'user' => self::text($params, 'user'),
            'password' => self::text($params, 'password'),
            'dbname' => self::text($params, 'dbname'),
        ]);
    }

    /**
     * Laesst die eigene Verbindung nicht ewig an einer Sperre haengen.
     *
     * Ohne die Grenze wartet PostgreSQL, bis die zweite Verbindung loslaesst —
     * im Test also bis zum Zeitlimit des ganzen Laufs.
     */
    protected static function stopWaitingQuickly(): void
    {
        self::entityManager()->getConnection()->executeStatement(
            "SET lock_timeout = '".self::LOCK_TIMEOUT."'",
        );
    }

    protected function closeSecondConnection(): void
    {
        if (null === $this->other) {
            return;
        }

        if ($this->other->isTransactionActive()) {
            $this->other->rollBack();
        }

        $this->other->close();
        $this->other = null;
    }

    /**
     * @param array<string, mixed> $params
     */
    private static function text(array $params, string $key): string
    {
        $value = $params[$key] ?? '';

        self::assertIsString($value, \sprintf('Verbindungsangabe "%s" fehlt', $key));

        return $value;
    }

    /**
     * @param array<string, mixed> $params
     */
    private static function port(array $params): int
    {
        $value = $params['port'] ?? 5432;

        return is_numeric($value) ? (int) $value : 5432;
    }

    private static function entityManager(): EntityManagerInterface
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);

        return $entityManager;
    }
}
