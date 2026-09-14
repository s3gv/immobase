<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Tests\Functional;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Haelt die erste tatsaechlich gebaute Zusage der Plugin-Grenze.
 *
 * /plugin-api/v1/theme.css ist oeffentliche Schnittstelle. Wer sie bricht,
 * bricht fremde Plugins — und merkt es ohne diesen Test nicht.
 */
final class PluginThemeCssTest extends WebTestCase
{
    private const PATH = '/plugin-api/v1/theme.css';

    public function testIsReachableWithoutSigningIn(): void
    {
        $client = self::createClient();
        $client->request('GET', self::PATH);

        self::assertResponseIsSuccessful('Ein Plugin hat keine Sitzung des Cores.');
        self::assertResponseHeaderSame('Content-Type', 'text/css; charset=UTF-8');
    }

    /**
     * Die Quelldatei ist Tailwind-Eingabe. Wuerde sie unveraendert
     * ausgeliefert, bekaeme ein Plugin keine Gestaltung, sondern eine
     * Anweisung, die es nicht ausfuehren kann.
     */
    public function testDeliversCompiledCssAndNotTheTailwindSource(): void
    {
        $client = self::createClient();
        $client->request('GET', self::PATH);

        $css = (string) $client->getResponse()->getContent();

        self::assertStringNotContainsString('@import "tailwindcss"', $css);
        self::assertStringContainsString('.ib-button', $css, 'Die eigenen Bausteine müssen enthalten sein.');
        self::assertStringContainsString('--ib-accent', $css, 'Die Farbtoken müssen enthalten sein.');
    }

    public function testMayBeLoadedFromAnotherOrigin(): void
    {
        $client = self::createClient();
        $client->request('GET', self::PATH);

        self::assertResponseHeaderSame('Access-Control-Allow-Origin', '*');
    }

    /**
     * Eine Frist im Cache-Control unterbindet die Rueckfrage, solange sie
     * laeuft. Ein Plugin liefe dann nach einer Aenderung am Designsystem mit
     * veralteter Gestaltung weiter — und niemand saehe warum.
     */
    public function testAsksBackOnEveryRequestInsteadOfSettingATime(): void
    {
        $client = self::createClient();
        $client->request('GET', self::PATH);

        $cacheControl = (string) $client->getResponse()->headers->get('Cache-Control');

        self::assertStringContainsString('no-cache', $cacheControl);
        self::assertStringNotContainsString('max-age', $cacheControl, 'Eine Frist verhindert die Rückfrage.');
    }

    /**
     * Der stabile Pfad kostet, was der Pruefsummen-Dateiname geleistet hat:
     * eine geaenderte Datei traegt dieselbe Adresse. Die Rueckfrage ueber
     * ETag holt das zurueck.
     */
    public function testAnswersWithoutBodyWhenTheCallerAlreadyHasIt(): void
    {
        $client = self::createClient();
        $client->request('GET', self::PATH);

        $etag = $client->getResponse()->getEtag();

        self::assertIsString($etag);

        $client->request('GET', self::PATH, [], [], ['HTTP_IF_NONE_MATCH' => $etag]);

        self::assertResponseStatusCodeSame(304);
        self::assertSame('', (string) $client->getResponse()->getContent());
    }
}
