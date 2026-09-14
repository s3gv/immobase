<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Shared\Http\CspNonce;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Was jede Antwort an Schutz mitbringt.
 *
 * Die Content-Security-Policy erlaubt Skripte nur von hier und das eine
 * eingebettete nur mit der Nonce dieser Anfrage. Eine eingeschleuste Zeile
 * HTML fuehrt damit kein Skript aus, auch wenn sie irgendwo durchrutscht.
 */
final class SecurityHeadersTest extends WebTestCase
{
    public function testEveryPageCarriesTheProtectiveHeaders(): void
    {
        $client = self::createClient();
        $client->request('GET', '/login');
        $headers = $client->getResponse()->headers;

        self::assertSame('nosniff', $headers->get('X-Content-Type-Options'));
        self::assertSame('DENY', $headers->get('X-Frame-Options'));
        self::assertSame('same-origin', $headers->get('Referrer-Policy'));
        self::assertSame('same-origin', $headers->get('Cross-Origin-Opener-Policy'));
        self::assertStringContainsString('camera=()', (string) $headers->get('Permissions-Policy'));

        $policy = (string) $headers->get('Content-Security-Policy');
        foreach (["default-src 'self'", "frame-ancestors 'none'", "object-src 'none'", "base-uri 'none'", "form-action 'self'"] as $directive) {
            self::assertStringContainsString($directive, $policy);
        }
    }

    public function testTheInlineScriptCarriesTheNonceOfThisRequest(): void
    {
        $client = self::createClient();
        $crawler = $client->request('GET', '/login');

        $found = preg_match("/script-src 'self' 'nonce-([A-Za-z0-9+\\/=]{22,})'/", (string) $client->getResponse()->headers->get('Content-Security-Policy'), $match);
        self::assertSame(1, $found);

        $scripts = $crawler->filter('script:not([src])');
        self::assertGreaterThan(0, $scripts->count());
        $scripts->each(static fn ($script) => self::assertSame($match[1], $script->attr('nonce')));
    }

    public function testNoScriptComesFromElsewhere(): void
    {
        $client = self::createClient();
        $client->request('GET', '/login');
        $html = (string) $client->getResponse()->getContent();

        self::assertStringNotContainsString('https://ga.jspm.io', $html, 'Kein Nachladen von fremden Servern');
        self::assertDoesNotMatchRegularExpression('/\son[a-z]+="/', $html, 'Keine Inline-Handler — die CSP verbietet sie');
    }

    /** Ein Stylesheet-Import im Modul wird zu einer data:-URL, und die ist kein erlaubtes Skript. */
    public function testTheStylesheetIsLinkedAndNotImported(): void
    {
        $client = self::createClient();
        $crawler = $client->request('GET', '/login');

        self::assertCount(1, $crawler->filter('link[rel="stylesheet"][href*="styles/app"]'));
        self::assertStringNotContainsString('data:', $crawler->filter('script[type="importmap"]')->text());
    }

    public function testOnlyTheStyleBlocksOfAForeignFragmentGetTheNonce(): void
    {
        $nonce = new CspNonce();

        $html = $nonce->styles('<style>.a{}</style><STYLE media="print">.b{}</STYLE><script>x()</script><styles>');

        self::assertSame(2, substr_count($html, 'nonce="'.$nonce->value().'"'));
        self::assertStringContainsString('<script>x()</script><styles>', $html);
    }

    /** HSTS nur, wo die Verbindung verschluesselt ist: auf http wuerde es nichts bewirken und verwirren. */
    public function testStrictTransportSecurityOnlyOverHttps(): void
    {
        $client = self::createClient();

        $client->request('GET', '/login');
        self::assertFalse($client->getResponse()->headers->has('Strict-Transport-Security'));

        $client->request('GET', '/login', server: ['HTTPS' => 'on']);
        self::assertStringContainsString('max-age=', (string) $client->getResponse()->headers->get('Strict-Transport-Security'));
    }
}
