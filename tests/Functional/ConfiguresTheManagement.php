<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Module\Settings\Application\Settings;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Eine Verwaltung, die erreichbar ist — Name, Telefon, E-Mail.
 *
 * Jede E-Rechnung nennt einen Ansprechpartner und eine Adresse fuer Antworten
 * (BG-6, BT-34), und die kommen aus den Einstellungen. Wer eine Rechnung mit
 * Umsatzsteuer ausstellt, braucht sie; alle anderen Tests laufen mit einer
 * frischen Installation ohne Organisation und sollen das auch weiter tun.
 */
trait ConfiguresTheManagement
{
    protected static function theManagementIsReachable(): void
    {
        $settings = self::getContainer()->get(Settings::class);
        self::assertInstanceOf(Settings::class, $settings);

        $settings->setTexts([
            'organisation.name' => 'Prüfverwaltung GmbH',
            'organisation.phone' => '0211 4711 00',
            'organisation.email' => 'verwaltung@example.org',
        ]);
    }

    protected static function forgetTheManagement(): void
    {
        if (null === self::$kernel) {
            return;
        }

        $manager = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $manager);
        $manager->getConnection()->executeStatement("DELETE FROM settings WHERE name LIKE 'organisation.%'");
        // Die Einstellungen stehen sonst noch im Speicher der Anfrage.
        $manager->clear();
    }
}
