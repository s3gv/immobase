<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Shared\Http\PublicUrls;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Routing\RequestContext;
use Symfony\Component\Routing\RouterInterface;

/**
 * Der Host einer Anfrage ist Eingabe.
 *
 * Wer `Host: evil.example` schickt und dann "Passwort vergessen" fuer ein
 * fremdes Konto ausloest, darf keinen Link auf seinen Server in eine echte
 * Mail von hier bekommen.
 */
final class HostHeaderTest extends WebTestCase
{
    public function testAForeignHostIsRefused(): void
    {
        $client = self::createClient();
        $client->request('GET', '/passwort/vergessen', server: ['HTTP_HOST' => 'evil.example']);

        self::assertResponseStatusCodeSame(400);
    }

    public function testTheOwnNameAndLocalhostAreAnswered(): void
    {
        $client = self::createClient();

        $client->request('GET', '/login', server: ['HTTP_HOST' => 'localhost']);
        self::assertResponseIsSuccessful();

        $client->request('GET', '/login', server: ['HTTP_HOST' => '127.0.0.1']);
        self::assertResponseIsSuccessful();
    }

    public function testLinksThatLeaveTheHouseIgnoreTheHostOfTheRequest(): void
    {
        self::bootKernel();
        $router = self::getContainer()->get(RouterInterface::class);
        self::assertInstanceOf(RouterInterface::class, $router);
        $router->setContext(new RequestContext(host: 'evil.example', scheme: 'https'));

        $links = self::getContainer()->get(PublicUrls::class);
        self::assertInstanceOf(PublicUrls::class, $links);

        $address = $_SERVER['DEFAULT_URI'] ?? $_ENV['DEFAULT_URI'] ?? '';
        self::assertIsString($address);
        self::assertStringStartsWith(rtrim($address, '/').'/passwort/neu/', $links->absolute('app_password_reset_link', ['token' => 'abc']));
        self::assertSame('evil.example', $router->getContext()->getHost(), 'Der Kontext der Anfrage bleibt, wie er war');
    }
}
