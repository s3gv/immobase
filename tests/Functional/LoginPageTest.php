<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Tests\Functional;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Was die Anmeldeseite ueber sich preisgibt.
 *
 * Sie steht jedem offen, der die Adresse kennt — was hier hinausgeht, geht an
 * die ganze Welt.
 */
final class LoginPageTest extends WebTestCase
{
    /**
     * Eine fehlgeschlagene Anmeldung zeigte den kompletten Stacktrace.
     *
     * Der Feldbaustein wurde ohne `only` eingebunden und erbte damit die
     * Variable `error` der Seite — die Ausnahme aus der Anmeldung. Sein
     * eigenes `error` ist sonst ein Satz wie "Bitte den Ort angeben"; hier
     * war es ein Objekt, und Twig hat es ausgegeben: Klassennamen,
     * Dateipfade, Zeilennummern.
     */
    public function testAFailedSignInRevealsNothingAboutTheServer(): void
    {
        $client = self::createClient();

        $crawler = $client->request('GET', '/login');
        $client->submit($crawler->selectButton('Anmelden')->form([
            '_username' => 'niemand@example.org',
            '_password' => 'falsch',
        ]));

        $html = $client->followRedirect()->html();

        self::assertStringContainsString('E-Mail-Adresse oder Passwort', $html, 'Die neutrale Meldung steht da');

        foreach (['Exception', 'Stack trace', '/vendor/', '/app/src/'] as $leak) {
            self::assertStringNotContainsString($leak, $html, \sprintf('"%s" gehört nicht auf diese Seite', $leak));
        }
    }

    /**
     * Beide Faelle — Adresse unbekannt und Passwort falsch — sehen gleich aus.
     * Sonst verraet das Formular, welche Adressen hier Konten haben.
     */
    public function testTheMessageDoesNotSayWhichHalfWasWrong(): void
    {
        $client = self::createClient();

        $crawler = $client->request('GET', '/login');
        $client->submit($crawler->selectButton('Anmelden')->form([
            '_username' => 'niemand@example.org',
            '_password' => 'falsch',
        ]));

        $html = $client->followRedirect()->html();

        self::assertStringNotContainsString('unbekannt', $html);
        self::assertStringNotContainsString('nicht gefunden', $html);
    }
}
