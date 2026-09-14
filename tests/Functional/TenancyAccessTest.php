<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Module\Tenancy\Domain\TenancyPermissions;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Was jemand ohne das Recht sieht — und was er nicht kann.
 *
 * Zweimal dieselbe Frage, weil ein abgeschalteter Knopf niemanden aufhaelt,
 * der die Adresse kennt: einmal die Oberflaeche, einmal der direkte Aufruf.
 */
final class TenancyAccessTest extends WebTestCase
{
    use ForgetsRateLimits;
    use SignsIn;

    protected function tearDown(): void
    {
        self::removeTestUser();

        parent::tearDown();
    }

    public function testWithoutTheViewPermissionThereIsNoMenuEntry(): void
    {
        $client = self::signedInWith(['parties.view']);

        $client->request('GET', '/');

        self::assertResponseIsSuccessful();
        self::assertStringNotContainsString('/miete', (string) $client->getResponse()->getContent());
    }

    public function testWithoutTheViewPermissionTheListIsForbidden(): void
    {
        $client = self::signedInWith(['parties.view']);

        $client->request('GET', '/miete');

        self::assertResponseStatusCodeSame(403);
    }

    /** Lesen darf, wer sehen darf — aber anlegen nicht. */
    public function testReadingDoesNotAllowEditing(): void
    {
        $client = self::signedInWith([TenancyPermissions::VIEW]);

        $client->request('GET', '/miete');
        self::assertResponseIsSuccessful();
        self::assertStringNotContainsString('/miete/neu', (string) $client->getResponse()->getContent());

        $client->request('GET', '/miete/neu');
        self::assertResponseStatusCodeSame(403);
    }

    /** Und die Suche des Pickers ist auch eine Bearbeitung. */
    public function testTheUnitSearchNeedsTheEditPermission(): void
    {
        $client = self::signedInWith([TenancyPermissions::VIEW]);

        $client->request('GET', '/miete/einheiten/suche?q=Rosen');

        self::assertResponseStatusCodeSame(403);
    }

    protected static function testEmail(): string
    {
        return 'mietrechte@example.org';
    }
}
