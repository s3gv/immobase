<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Settings\Infrastructure;

use App\Module\Settings\Domain\Setting;
use App\Module\Settings\Domain\SettingRepository;
use Doctrine\ORM\EntityManagerInterface;

final readonly class DoctrineSettingRepository implements SettingRepository
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function find(string $name): ?Setting
    {
        return $this->entityManager->getRepository(Setting::class)->find($name);
    }

    public function save(Setting $setting): void
    {
        $this->saveAll([$setting]);
    }

    /**
     * Ein einziges `flush()` — Doctrine legt darum eine Transaktion.
     *
     * Vierzehn einzelne Fluesche waeren vierzehn Transaktionen, und die
     * dreizehnte kann scheitern.
     */
    public function saveAll(array $settings): void
    {
        foreach ($settings as $setting) {
            $this->entityManager->persist($setting);
        }

        $this->entityManager->flush();
    }
}
