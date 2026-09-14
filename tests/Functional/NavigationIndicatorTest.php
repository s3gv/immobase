<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Tests\Functional;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Haelt die Markierung in der Hauptnavigation ehrlich.
 *
 * Sie wird aus dem Routennamen abgeleitet. Wenn spaeter Fachmodule dazukommen,
 * ist das die Stelle, an der es still schiefgehen kann: entweder ist gar nichts
 * markiert, oder es leuchtet ein Eintrag, auf dem man gar nicht steht.
 *
 * Hier steht nur der Fall auf der echten Seite. Die Regel selbst — Praefix mit
 * Unterstrich, fremde Routen bleiben dunkel — pruefen die Bausteintests, weil
 * es dafuer bisher nur eine Seite mit Navigation gibt.
 */
final class NavigationIndicatorTest extends WebTestCase
{
    use SignsIn;

    protected function tearDown(): void
    {
        self::removeTestUser();

        parent::tearDown();
    }

    public function testMarksTheEntryOfThePageYouAreOn(): void
    {
        $client = self::signedInAs(asAdministrator: true);
        $client->request('GET', '/');

        self::assertSelectorExists('.ib-nav__link.is-current[aria-current="page"]');
        self::assertSelectorTextContains('.ib-nav__link.is-current', 'Übersicht');
    }

    protected static function testEmail(): string
    {
        return 'nav-test@example.org';
    }
}
