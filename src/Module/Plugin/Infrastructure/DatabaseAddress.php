<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Plugin\Infrastructure;

use Doctrine\DBAL\Connection;

/**
 * Wo die Datenbank steht — so, wie ein Plugin sie erreichen darf.
 *
 * Als IP-Adresse und nicht als Name: die Netzsperre kennt nur Adressen, und
 * ein Plugin ohne Internet hat keinen Namensdienst, der `database` aufloesen
 * koennte.
 */
final readonly class DatabaseAddress
{
    private function __construct(
        public string $host,
        public int $port,
    ) {
    }

    public static function of(Connection $connection): self
    {
        $parts = $connection->getParams();

        return new self(
            \is_string($parts['host'] ?? null) ? $parts['host'] : 'localhost',
            \is_int($parts['port'] ?? null) ? $parts['port'] : 5432,
        );
    }

    /**
     * Die IPv4-Adressen des Hosts. Eine Adresse bleibt, was sie ist; ein Name,
     * der sich nicht aufloesen laesst, ergibt keine — und damit keinen Zugang.
     *
     * @return list<string>
     */
    public function ipv4(): array
    {
        if (false !== filter_var($this->host, \FILTER_VALIDATE_IP, \FILTER_FLAG_IPV4)) {
            return [$this->host];
        }

        $found = gethostbynamel('localhost' === $this->host ? '127.0.0.1' : $this->host);

        return false === $found ? [] : array_values(\array_slice($found, 0, 8));
    }

    /** Die erste IPv4-Adresse, sonst der Name, wie er dasteht. */
    public function forPlugins(): string
    {
        return $this->ipv4()[0] ?? $this->host;
    }
}
