<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Module\Portal\Domain\PortalPermissions;
use App\Module\Property\Domain\PropertyPermissions;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Die Todo-Kachel der Uebersicht.
 *
 * Drei Zusagen. **Was wartet, steht da** — und zwar mit dem Knopf, mit dem
 * man es erledigt. **Was jemand nicht sehen darf, steht nicht da**, auch
 * nicht als Zahl: eine Zahl ist schon eine Auskunft. Und **wenn nichts offen
 * ist, sieht das gut aus**: ein Satz, kein leerer Kasten.
 */
final class DashboardTodoTest extends WebTestCase
{
    use BuildsABillableProperty;
    use ForgetsRateLimits;
    use SignsIn;

    protected function tearDown(): void
    {
        self::removeTheProperty();
        self::removeTestUser();

        parent::tearDown();
    }

    /**
     * Ein Objekt im Entwurf ist angefangene Arbeit — und fuehrt dorthin.
     *
     * Der Knopf traegt den Filter mit: `zustand` beim einen Modul, `status`
     * beim anderen. Ein Knopf, der den falschen Namen mitgibt, fuehrt in eine
     * ungefilterte Liste, und das faellt niemandem auf, weil dort trotzdem
     * etwas steht.
     */
    public function testADraftShowsUpWithTheWayBackToIt(): void
    {
        $client = self::signedInWith([PropertyPermissions::VIEW]);
        self::buildTheProperty();

        $client->request('GET', '/');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.ib-todo', 'Objekt im Entwurf');
        self::assertSelectorExists('.ib-todo a[href="/objekte?status=draft"]');
    }

    /**
     * Was jemand nicht sehen darf, steht auch nicht als Zahl da.
     *
     * Dasselbe Objekt, ein anderes Konto: wer nur die Anfragen sehen darf,
     * erfaehrt von einem Objekt im Entwurf nichts.
     */
    public function testWhatSomeoneMayNotSeeIsNotCountedEither(): void
    {
        $client = self::signedInWith([PortalPermissions::VIEW]);
        self::buildTheProperty();

        $client->request('GET', '/');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextNotContains('.ib-todo', 'Entwurf');
    }

    /** Der gute Fall ist der haeufige, und er soll gut aussehen. */
    public function testWithNothingOpenTheTileSaysSo(): void
    {
        $client = self::signedInWith([PortalPermissions::VIEW]);

        $client->request('GET', '/');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.ib-todo', 'Nichts offen');
    }

    protected static function testEmail(): string
    {
        return 'uebersicht@example.org';
    }
}
