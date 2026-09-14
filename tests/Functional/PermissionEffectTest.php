<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Module\Party\Domain\PartyPermissions;
use App\Module\Settings\Application\SettingsPage;
use App\Module\Settings\Contract\SettingsPermissions;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Was ein Konto sieht und was ihm verwehrt bleibt.
 *
 * Der interessante Fall ist durchweg der, in dem jemand *nicht* alles darf:
 * eine Anwendung, die nur fuer Administratoren stimmt, hat kein Rechtemodell,
 * sondern einen Schalter.
 */
final class PermissionEffectTest extends WebTestCase
{
    use ForgetsRateLimits;
    use SignsIn;

    protected function tearDown(): void
    {
        self::removeTestUser();

        parent::tearDown();
    }

    public function testViewingContactsWithoutEditing(): void
    {
        $client = self::signedInWith([PartyPermissions::VIEW]);

        $client->request('GET', '/stammdaten');
        self::assertResponseIsSuccessful();

        $client->request('GET', '/stammdaten/neu');
        self::assertResponseStatusCodeSame(403, 'Anlegen ist bearbeiten');
    }

    public function testTheMenuOnlyShowsWhatTheAccountMayOpen(): void
    {
        $client = self::signedInWith([PartyPermissions::VIEW]);

        $client->request('GET', '/');
        $page = (string) $client->getResponse()->getContent();

        self::assertStringContainsString('/stammdaten', $page);
        self::assertStringNotContainsString('href="/benutzer"', $page, 'Ohne users.view kein Menüeintrag');
        self::assertStringNotContainsString('href="/rollen"', $page);
        self::assertStringNotContainsString('href="/einstellungen"', $page);
    }

    public function testWithoutAPermissionTheDirectAddressIsRefused(): void
    {
        $client = self::signedInWith([PartyPermissions::VIEW]);

        foreach (['/benutzer', '/rollen', '/einstellungen'] as $url) {
            $client->request('GET', $url);
            self::assertResponseStatusCodeSame(403, $url.' braucht ein eigenes Recht');
        }
    }

    /** Das Dashboard und „Mein Konto" brauchen keine Berechtigung. */
    public function testTheDashboardAndTheOwnAccountStayOpen(): void
    {
        $client = self::signedInWith([]);

        $client->request('GET', '/');
        self::assertResponseIsSuccessful();

        $client->request('GET', '/mein-konto');
        self::assertResponseIsSuccessful();
    }

    /**
     * Ansehen und Aendern sind zwei Rechte — auch dort, wo beides auf
     * derselben Seite steht.
     */
    public function testSettingsCanBeReadWithoutBeingChanged(): void
    {
        $client = self::signedInWith([SettingsPermissions::VIEW]);

        foreach (SettingsPage::SECTIONS as $section) {
            $client->request('GET', '/einstellungen?abschnitt='.$section);

            self::assertResponseIsSuccessful('Abschnitt '.$section);
            // „Kein Speichern" meint die Seite. Die Kopfzeile traegt seit den
            // Erinnerungen einen eigenen Knopf, und der gehoert niemandem
            // Fremdes — jeder darf sich selbst etwas notieren.
            self::assertSelectorNotExists('main button[type="submit"]', 'Kein Speichern in '.$section);
            self::assertSelectorNotExists('main fieldset:not([disabled])', 'Kein offenes Feld in '.$section);
        }

        // Jeder Abschnitt speichert für sich — also braucht auch jeder seine
        // eigene Absage.
        foreach (['/einstellungen/app', '/einstellungen/sicherheit'] as $route) {
            $client->request('POST', $route, ['_token' => 'egal']);

            self::assertResponseStatusCodeSame(403, $route);
        }
    }

    public function testDeletingAContactNeedsItsOwnPermission(): void
    {
        $client = self::signedInWith([PartyPermissions::VIEW, PartyPermissions::EDIT]);

        $client->request('POST', '/stammdaten/1/loeschen', ['_token' => 'egal']);

        self::assertResponseStatusCodeSame(403, 'Bearbeiten schließt Löschen nicht ein');
    }

    /**
     * Und der Knopf dazu steht gar nicht erst da.
     *
     * Ein Knopf, der zuverlässig in eine Fehlermeldung führt, ist schlechter
     * als keiner — das gilt in jeder Übersicht, nicht nur im Menü.
     */
    public function testActionsWithoutAPermissionAreNotDrawn(): void
    {
        $client = self::signedInWith([PartyPermissions::VIEW]);

        $client->request('GET', '/stammdaten');
        $page = (string) $client->getResponse()->getContent();

        self::assertStringNotContainsString('/loeschen', $page);
        self::assertStringNotContainsString('/bearbeiten', $page);
        self::assertStringNotContainsString('/stammdaten/neu', $page);
    }

    protected static function testEmail(): string
    {
        return 'wirkung@example.org';
    }
}
