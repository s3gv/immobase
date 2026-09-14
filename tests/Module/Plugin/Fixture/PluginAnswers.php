<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Tests\Module\Plugin\Fixture;

use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Ein Plugin, das auf Kommando antwortet oder eben nicht.
 *
 * Die Zusicherung ist nicht, dass der Proxy ein Fragment durchreicht — das
 * sieht man ohnehin. Sie ist, dass ein **totes** Plugin die Anwendung nicht
 * mitnimmt, und dafuer muss eines auf Kommando tot sein koennen.
 */
final class PluginAnswers extends MockHttpClient
{
    public string $body = '<p class="ib-note">Prüfseite</p>';

    public int $status = 200;

    public bool $silent = false;

    public function __construct()
    {
        parent::__construct(function (): MockResponse {
            if ($this->silent) {
                return new MockResponse('', ['error' => 'Connection refused']);
            }

            return new MockResponse($this->body, ['http_code' => $this->status]);
        });
    }
}
