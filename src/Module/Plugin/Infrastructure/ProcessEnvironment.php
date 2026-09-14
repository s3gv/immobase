<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Plugin\Infrastructure;

/**
 * Die Umgebung eines Plugin-Prozesses.
 *
 * **Sie wird nicht vererbt.** Der Prozess des Cores traegt `DATABASE_URL` mit
 * vollem Zugang, `APP_SECRET` und den Schluessel der Anhaenge. Ein Plugin, das
 * das erbte, braeuchte keine eingeschraenkte Datenbankrolle mehr — es haette
 * den Schluessel zum ganzen Haus. Es bekommt deshalb nur `PATH` und das, was
 * ausdruecklich uebergeben wird. Dasselbe gilt fuer Composer, wenn es die
 * Abhaengigkeiten eines Plugins installiert.
 */
final class ProcessEnvironment
{
    /** Was aus der eigenen Umgebung weitergereicht wird — und nichts sonst. */
    private const array INHERITED = ['PATH'];

    /**
     * Genau die uebergebenen Werte, dazu `PATH` — sonst nichts.
     *
     * @param array<string, string> $given
     *
     * @return array<string, string>
     */
    public static function only(array $given): array
    {
        $inherited = [];

        foreach (self::INHERITED as $key) {
            $value = getenv($key);

            if (\is_string($value)) {
                $inherited[$key] = $value;
            }
        }

        return [...$inherited, ...$given];
    }

    /**
     * Was die Caddyfile des Plugins einsetzt, und wohin Caddy schreibt.
     *
     * Ohne eigenes Zuhause schreibt Caddy nach `./caddy` — und das
     * Arbeitsverzeichnis ist das Plugin selbst.
     *
     * @return array<string, string>
     */
    public static function forCaddy(string $home, int $port, string $root): array
    {
        return [
            'HOME' => $home,
            'XDG_DATA_HOME' => $home,
            'XDG_CONFIG_HOME' => $home,
            'IMMOBASE_PLUGIN_PORT' => (string) $port,
            'IMMOBASE_PLUGIN_ROOT' => $root,
        ];
    }
}
