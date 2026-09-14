<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Module\Portal\Domain\PortalPermissions;
use App\Module\Property\Domain\PropertyPermissions;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Die Zahlen unter der Todo-Kachel.
 *
 * Eine Zahl ist eine Auskunft: „offene Forderungen: 48.200 €" sagt etwas
 * ueber das Geschaeft, auch ohne den dazugehoerigen Datensatz. Deshalb steht
 * jede Gruppe nur da, wo das Recht dafuer da ist — und eine Gruppe ohne
 * sichtbare Zahl steht gar nicht da.
 */
final class DashboardFigureTest extends WebTestCase
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

    /** Wer die Objekte sehen darf, sieht den Bestand — und sonst nichts. */
    public function testEachGroupNeedsItsOwnRight(): void
    {
        $client = self::signedInWith([PropertyPermissions::VIEW]);
        self::buildTheProperty();

        $client->request('GET', '/');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Objekte in Verwaltung');
        self::assertSelectorTextNotContains('body', 'Offene Forderungen');
        self::assertSelectorTextNotContains('body', 'Abrechnungen freigegeben');
    }

    /** Jede Zahl fuehrt dorthin, wo sie erklaert wird. */
    public function testAFigureLeadsToThePageThatExplainsIt(): void
    {
        $client = self::signedInWith([PropertyPermissions::VIEW]);
        self::buildTheProperty();

        $client->request('GET', '/');

        self::assertSelectorExists('.ib-figures a[href="/objekte"]');
    }

    /**
     * Ein Objekt und drei Einheiten — dieselben Zahlen wie in den Listen.
     *
     * Die Probe darauf, dass hier gezaehlt und nicht geschaetzt wird: das
     * Bauwerk der Tests legt genau ein Objekt mit drei Einheiten an.
     */
    public function testTheNumbersAreTheOnesTheListsShow(): void
    {
        $client = self::signedInWith([PropertyPermissions::VIEW]);
        self::buildTheProperty();

        $page = $client->request('GET', '/');

        $stock = $page->filter('.ib-figures')->first();

        self::assertStringContainsString('1', $stock->text());
        self::assertStringContainsString('Objekte in Verwaltung', $stock->text());
        self::assertStringContainsString('Einheiten', $stock->text());
    }

    /**
     * Wer nur die Anfragen sehen darf, bekommt nur diese Gruppe.
     *
     * Und keine leeren Abschnitte daneben: eine Ueberschrift ohne Zahl
     * darunter ist keine Auskunft, sondern eine Luecke, die nach einem Fehler
     * aussieht.
     */
    public function testAGroupWithoutAVisibleFigureIsNotThere(): void
    {
        $client = self::signedInWith([PortalPermissions::VIEW]);

        $client->request('GET', '/');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Offene Anfragen');
        self::assertSelectorTextNotContains('body', 'Bestand');
        self::assertSelectorTextNotContains('body', 'Abrechnungsjahr');
    }

    protected static function testEmail(): string
    {
        return 'zahlen@example.org';
    }
}
