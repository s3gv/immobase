<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Tests\Functional;

use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class LocaleSwitchTest extends WebTestCase
{
    use SignsIn;

    protected function tearDown(): void
    {
        self::removeTestUser();

        parent::tearDown();
    }

    /**
     * Adressen, die vor der Korrektur als "eigene" durchgingen.
     *
     * Ein blosses str_starts_with auf die eigene Adresse akzeptiert jede
     * Domain, die mit demselben Text beginnt.
     *
     * @return iterable<string, array{string}>
     */
    public static function foreignReferers(): iterable
    {
        yield 'angehängte Subdomain' => ['https://localhost.evil.example/beute'];
        yield 'angehängter Domainname' => ['https://localhost-evil.example/beute'];
        yield 'fremde Domain' => ['https://evil.example/beute'];
        yield 'protokollrelativ' => ['//evil.example/beute'];
    }

    #[DataProvider('foreignReferers')]
    public function testDoesNotRedirectToAForeignAddress(string $referer): void
    {
        $client = self::signedInAs();
        $client->request('GET', '/locale/en', [], [], ['HTTP_REFERER' => $referer]);

        $location = $client->getResponse()->headers->get('Location');

        self::assertNotNull($location);
        self::assertStringNotContainsString('evil.example', $location);
        self::assertSame('/', $location, 'Bei fremdem Referer wird auf die eigene Übersicht geleitet');
    }

    public function testTreatsADifferentSchemeAsForeign(): void
    {
        // Der Client spricht hier https; ein http-Referer ist damit eine
        // andere Herkunft, auch wenn der Host derselbe ist.
        $client = self::signedInAs(['HTTPS' => true]);
        $client->request('GET', '/locale/en', [], [], ['HTTP_REFERER' => 'http://localhost/geheim']);

        self::assertResponseRedirects('/');
    }

    public function testReturnsToTheOwnPage(): void
    {
        $client = self::signedInAs();
        $client->request('GET', '/locale/en', [], [], ['HTTP_REFERER' => 'http://localhost/login?x=1']);

        self::assertResponseRedirects('/login?x=1');
    }

    public function testRedirectsToTheDashboardWithoutAReferer(): void
    {
        $client = self::signedInAs();
        $client->request('GET', '/locale/de');

        self::assertResponseRedirects('/');
    }

    protected static function testEmail(): string
    {
        return 'locale-test@example.org';
    }
}
