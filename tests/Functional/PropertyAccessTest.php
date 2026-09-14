<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Module\Property\Domain\PropertyPermissions;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Wer die Objekte sehen darf — und wer sie anfassen darf.
 *
 * Einheiten laufen mit: sie sind Teil eines Objekts und kein eigener Bereich.
 */
final class PropertyAccessTest extends WebTestCase
{
    use ForgetsRateLimits;
    use SignsIn;

    protected function tearDown(): void
    {
        self::removeTestUser();

        parent::tearDown();
    }

    public function testWithoutTheViewPermissionThereIsNoMenuEntryAndNoPage(): void
    {
        $client = self::signedInWith([]);

        $client->request('GET', '/');
        self::assertStringNotContainsString('href="/objekte"', (string) $client->getResponse()->getContent());

        $client->request('GET', '/objekte');
        self::assertResponseStatusCodeSame(403);
    }

    public function testViewingWithoutEditing(): void
    {
        $client = self::signedInWith([PropertyPermissions::VIEW]);

        $client->request('GET', '/objekte');
        $page = (string) $client->getResponse()->getContent();

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('href="/objekte"', $page);
        // Was jemand nicht darf, steht gar nicht erst da.
        self::assertStringNotContainsString('/objekte/neu', $page);

        $client->request('GET', '/objekte/neu');
        self::assertResponseStatusCodeSame(403);
    }

    public function testEditingDoesNotIncludeDeleting(): void
    {
        $client = self::signedInWith([PropertyPermissions::VIEW, PropertyPermissions::EDIT]);

        $client->request('POST', '/objekte/20001/loeschen', ['_token' => 'egal']);

        self::assertResponseStatusCodeSame(403, 'Bearbeiten schließt Löschen nicht ein');
    }

    /** Auch die Vervollstaendigung ist kein offener Endpunkt. */
    public function testTheOwnerSearchNeedsTheEditPermission(): void
    {
        $client = self::signedInWith([PropertyPermissions::VIEW]);

        $client->request('GET', '/objekte/eigentuemer/suche?q=Mustermann');

        self::assertResponseStatusCodeSame(403);
    }

    /** Einheiten haben keinen eigenen Bereich — sie folgen dem Objekt. */
    public function testUnitsFollowThePropertyPermissions(): void
    {
        $client = self::signedInWith([]);

        foreach (['/objekte/20001/einheiten/1', '/objekte/20001/einheiten/neu'] as $url) {
            $client->request('GET', $url);
            self::assertResponseStatusCodeSame(403, $url);
        }
    }

    protected static function testEmail(): string
    {
        return 'objektrechte@example.org';
    }
}
