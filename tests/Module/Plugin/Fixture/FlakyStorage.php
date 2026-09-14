<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Tests\Module\Plugin\Fixture;

use App\Module\Plugin\Domain\PluginStorage;
use RuntimeException;

/**
 * Ein Speicher, der auf Kommando mittendrin scheitert.
 *
 * Er merkt sich, was „steht" — so laesst sich pruefen, ob nach einem Fehler
 * ein Rest uebrig bleibt. Gelingt alles, sieht man keinen Unterschied.
 *
 * Wie PostgreSQL legt er nichts zweimal an: steht der Speicher schon, weil
 * eine andere Aktivierung ihn gebaut hat, scheitert das Anlegen.
 */
final class FlakyStorage implements PluginStorage
{
    /** @var array<string, true> */
    public array $standing = [];

    public bool $createFails = false;
    public bool $dropFails = false;

    public function create(string $name, string $password, array $tables): void
    {
        if (isset($this->standing[$name])) {
            throw new RuntimeException('Schema existiert bereits');
        }

        if ($this->createFails) {
            throw new RuntimeException('Rolle ließ sich nicht anlegen');
        }

        $this->standing[$name] = true;
    }

    public function grow(string $name, array $tables): void
    {
    }

    public function drop(string $name): void
    {
        if ($this->dropFails) {
            throw new RuntimeException('Schema ließ sich nicht löschen');
        }

        unset($this->standing[$name]);
    }

    public function dsn(string $name, string $password): string
    {
        return '';
    }
}
