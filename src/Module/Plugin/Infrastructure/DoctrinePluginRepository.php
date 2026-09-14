<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Plugin\Infrastructure;

use App\Module\Plugin\Domain\Plugin;
use App\Module\Plugin\Domain\PluginRepository;
use App\Module\Plugin\Domain\PluginState;
use Doctrine\ORM\EntityManagerInterface;

final readonly class DoctrinePluginRepository implements PluginRepository
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function save(Plugin $plugin): void
    {
        $this->entityManager->persist($plugin);
        $this->entityManager->flush();
    }

    public function atomicallyFor(string $name, callable $work): mixed
    {
        return $this->entityManager->wrapInTransaction(function () use ($name, $work): mixed {
            // Bis zum Ende der Transaktion. Die Zahl kommt aus dem Namen; ein
            // Zusammenstoss zweier Namen liesse nur einen kurz warten.
            $this->entityManager->getConnection()->executeQuery(
                'SELECT pg_advisory_xact_lock(hashtext(:key))',
                ['key' => 'plugin:'.$name],
            );

            return $work();
        });
    }

    public function remove(Plugin $plugin): void
    {
        $this->entityManager->remove($plugin);
        $this->entityManager->flush();
    }

    public function byName(string $name): ?Plugin
    {
        return $this->entityManager->getRepository(Plugin::class)->findOneBy(['name' => $name]);
    }

    public function byTokenHash(string $hash): ?Plugin
    {
        return $this->entityManager->getRepository(Plugin::class)->findOneBy(['tokenHash' => $hash]);
    }

    public function usedPorts(): array
    {
        /** @var list<int|string> $ports */
        $ports = $this->entityManager->getConnection()->fetchFirstColumn('SELECT port FROM plugin_installation');

        return array_map(intval(...), $ports);
    }

    public function all(): array
    {
        /** @var list<Plugin> $found */
        $found = $this->entityManager->getRepository(Plugin::class)->findBy([], ['name' => 'ASC']);

        return $found;
    }

    public function active(): array
    {
        /** @var list<Plugin> $found */
        $found = $this->entityManager->getRepository(Plugin::class)
            ->findBy(['state' => PluginState::Active], ['name' => 'ASC']);

        return $found;
    }
}
