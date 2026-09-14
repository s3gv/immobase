<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Shared\Http;

use Closure;
use Symfony\Component\DependencyInjection\EnvVarProcessorInterface;
use Symfony\Component\DependencyInjection\Exception\RuntimeException;

/**
 * Unter welchen Namen ImmoBase antwortet: dem aus `DEFAULT_URI`, dazu
 * `localhost` fuer die Plugins im selben Container.
 *
 * Eine Anfrage mit einem anderen `Host` bekommt 400. Ohne diese Liste glaubte
 * Symfony jeden Namen, den der Absender schreibt — und alles, was daraus eine
 * Adresse baut, truege sie weiter. Abgeleitet statt eigens eingetragen, damit
 * die beiden Angaben nicht auseinanderlaufen koennen.
 *
 * In der Konfiguration: `trusted_hosts: '%env(trusted_hosts:DEFAULT_URI)%'`.
 */
final readonly class TrustedHosts implements EnvVarProcessorInterface
{
    private const array LOCAL = ['localhost', '127.0.0.1', '[::1]'];

    public function getEnv(string $prefix, string $name, Closure $getEnv): string
    {
        $value = $getEnv($name);
        $host = \is_string($value) ? parse_url($value, \PHP_URL_HOST) : null;

        if (!\is_string($host) || '' === $host) {
            throw new RuntimeException(\sprintf('%s enthaelt keinen Hostnamen.', $name));
        }

        $hosts = array_unique([strtolower($host), ...self::LOCAL]);

        return implode(',', array_map(static fn (string $one): string => '^'.preg_quote($one, '/').'$', $hosts));
    }

    public static function getProvidedTypes(): array
    {
        return ['trusted_hosts' => 'string'];
    }
}
