<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Plugin\Application;

use App\Module\Plugin\Domain\Plugin;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpFailure;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Der Core holt die Seite beim Plugin.
 *
 * Der Browser spricht nie mit dem Plugin: er fragt den Core, der Core prueft
 * Sitzung und Recht wie bei jeder anderen Seite und holt danach den Inhalt.
 * Damit braucht es kein zweites Passwort, kein Ticket und keine Signatur —
 * und das Plugin bleibt trotzdem ein eigener Prozess mit HTTP dazwischen.
 *
 * **Mit Grenzen.** Fuenf Sekunden, ein Mebibyte, keine Weiterleitungen. Ein
 * Plugin, das haengt, darf die Anwendung nicht mit haengen lassen, und eine
 * Weiterleitung waere die Stelle, an der ein Plugin den Core dazu braechte,
 * irgendetwas anderes abzurufen.
 */
final readonly class AsksThePlugin
{
    private const int TIMEOUT = 5;
    private const int LIMIT = 1048576;

    public function __construct(private HttpClientInterface $client)
    {
    }

    /** Das Fragment, das die Shell umschliesst — oder null, wenn nichts kam. */
    public function fragment(Plugin $plugin, string $path, Request $request): ?string
    {
        try {
            $response = $this->client->request('GET', $plugin->address().'/'.ltrim($path, '/'), [
                'query' => $request->query->all(),
                'timeout' => self::TIMEOUT,
                'max_redirects' => 0,
                'max_duration' => self::TIMEOUT,
                'headers' => ['Accept' => 'text/html', 'Accept-Language' => $request->getLocale()],
            ]);

            if (200 !== $response->getStatusCode()) {
                return null;
            }

            $body = $response->getContent(false);
        } catch (HttpFailure) {
            return null;
        }

        return \strlen($body) > self::LIMIT ? null : $body;
    }
}
