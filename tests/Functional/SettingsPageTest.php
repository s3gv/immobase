<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Module\Settings\Application\Settings;
use App\Module\Settings\Application\SettingsPage;
use App\Module\Settings\Contract\ApplicationSettings;
use App\Module\Settings\Contract\SettingsPermissions;
use App\Module\Settings\Domain\Setting;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Die Einstellungen in Abschnitten.
 *
 * Jeder speichert fuer sich — das ist die Entscheidung, an der die Seite
 * haengt, und deshalb steht sie hier als Test und nicht nur in der Spec.
 */
final class SettingsPageTest extends WebTestCase
{
    use ForgetsRateLimits;
    use SignsIn;

    protected function tearDown(): void
    {
        self::forgetSettings();
        self::removeTestUser();

        parent::tearDown();
    }

    /** Jeder Abschnitt zeichnet sich. */
    public function testEverySectionRenders(): void
    {
        $client = self::editor();

        foreach (SettingsPage::SECTIONS as $section) {
            $client->request('GET', '/einstellungen?abschnitt='.$section);

            self::assertResponseIsSuccessful('Abschnitt '.$section);
        }
    }

    /** Ein unbekannter Abschnitt ist Eingabe, kein Fehler. */
    public function testAnUnknownSectionFallsBackToTheFirst(): void
    {
        $client = self::editor();

        $client->request('GET', '/einstellungen?abschnitt=quatsch');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('form[action="/einstellungen/app"]');
    }

    /**
     * Der Schalter kann Bewegung nur wegnehmen.
     *
     * Gespeichert wird, was angekreuzt ist; ob es wirkt, entscheidet
     * zusaetzlich das Geraet.
     */
    public function testReducedMotionIsSavedAndReachesThePage(): void
    {
        $client = self::editor();

        self::post($client, '/einstellungen/app', ['reduced_motion' => '1']);

        self::assertTrue(self::settings()->bool(ApplicationSettings::REDUCED_MOTION, false));

        $client->request('GET', '/einstellungen');

        self::assertStringContainsString('data-motion="reduced"', (string) $client->getResponse()->getContent());
    }

    /** Und ohne den Schalter steht das Attribut nicht da. */
    public function testWithoutTheSwitchThereIsNoAttribute(): void
    {
        $client = self::editor();

        self::post($client, '/einstellungen/app', []);
        $client->request('GET', '/einstellungen');

        self::assertStringNotContainsString('data-motion', (string) $client->getResponse()->getContent());
    }

    /** Die Organisationsangaben stehen nach dem Speichern da. */
    public function testTheOrganisationIsSavedAndReadBack(): void
    {
        $client = self::editor();

        self::post($client, '/einstellungen/organisation', [
            'name' => 'Musterverwaltung GmbH',
            'street' => 'Musterweg 1',
            'postalCode' => '12345',
            'city' => 'Musterstadt',
            'email' => 'Post@Example.ORG',
            'iban' => 'DE89 3704 0044 0532 0130 00',
            'bic' => 'cobadeff',
            'accountHolder' => 'Musterverwaltung GmbH',
        ]);

        $organisation = self::settings()->organisation();

        self::assertSame('Musterverwaltung GmbH', $organisation->name);
        self::assertTrue($organisation->hasAddress());
        self::assertTrue($organisation->hasBank());
        // Getippt wird in Gruppen, gespeichert am Stück.
        self::assertSame('DE89370400440532013000', $organisation->iban);
        self::assertSame('COBADEFF', $organisation->bic);
        self::assertSame('post@example.org', $organisation->email);
    }

    /**
     * Eine falsche IBAN wirft nicht das ganze Formular weg.
     *
     * Gespeichert wird erst, wenn alles durchgeht: ein halb uebernommenes
     * Formular waere schlimmer als eines, das zurueckkommt.
     */
    public function testAWrongIbanComesBackWithoutLosingTheRest(): void
    {
        $client = self::editor();

        self::post($client, '/einstellungen/organisation', [
            'name' => 'Musterverwaltung GmbH',
            'iban' => 'DE89370400440532031000',
        ]);

        self::assertResponseIsSuccessful();
        $page = (string) $client->getResponse()->getContent();

        self::assertStringContainsString('keine gültige IBAN', $page);
        self::assertStringContainsString('Musterverwaltung GmbH', $page, 'Das Getippte steht noch da');
        self::assertSame('', self::settings()->organisation()->name, 'Und gespeichert wurde nichts');
    }

    /**
     * Eine zu lange Angabe kommt zurueck, und der alte Stand bleibt ganz.
     *
     * Die Spalte fasst 500 Zeichen. Wuerde erst sie das merken, waere die
     * Absage ein Datenbankfehler mitten im Speichern — und die Haelfte der
     * Angaben stuende dann schon neu da, die andere noch alt.
     */
    public function testAnOverlongEntryLeavesTheOldStandIntact(): void
    {
        $client = self::editor();
        self::post($client, '/einstellungen/organisation', [
            'name' => 'Musterverwaltung GmbH',
            'city' => 'Musterstadt',
        ]);

        self::post($client, '/einstellungen/organisation', [
            'name' => str_repeat('a', Setting::MOST_CHARACTERS + 1),
            'city' => 'Andernorts',
        ]);

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('zu lang', (string) $client->getResponse()->getContent());

        $organisation = self::settings()->organisation();

        self::assertSame('Musterverwaltung GmbH', $organisation->name);
        self::assertSame('Musterstadt', $organisation->city, 'Auch das gute Feld blieb, wie es war');
    }

    /** Genau 500 gehen noch. */
    public function testTheLastAllowedCharacterIsAccepted(): void
    {
        $client = self::editor();

        self::post($client, '/einstellungen/organisation', [
            'name' => str_repeat('a', Setting::MOST_CHARACTERS),
        ]);

        self::assertSame(
            str_repeat('a', Setting::MOST_CHARACTERS),
            self::settings()->organisation()->name,
        );
    }

    /** Eine frische Installation hat nichts davon — und das ist kein Fehler. */
    public function testAFreshInstallationHasNothing(): void
    {
        self::bootKernel();

        $organisation = self::settings()->organisation();

        self::assertSame('', $organisation->name);
        self::assertFalse($organisation->hasAddress());
        self::assertFalse($organisation->hasBank());
    }

    protected static function testEmail(): string
    {
        return 'einstellungen@example.org';
    }

    private static function editor(): KernelBrowser
    {
        return self::signedInWith([SettingsPermissions::VIEW, SettingsPermissions::EDIT]);
    }

    /**
     * @param array<string, string> $fields
     */
    private static function post(KernelBrowser $client, string $url, array $fields): void
    {
        $crawler = $client->request('GET', '/einstellungen?abschnitt=organisation');
        $token = (string) $crawler->filter('main input[name="_token"]')->first()->attr('value');

        $client->request('POST', $url, [...$fields, '_token' => $token]);
    }

    private static function settings(): Settings
    {
        $settings = self::getContainer()->get(Settings::class);
        self::assertInstanceOf(Settings::class, $settings);

        return $settings;
    }

    /** Die Tabelle ist installationsweit — was ein Test setzt, muss wieder weg. */
    private static function forgetSettings(): void
    {
        if (null === self::$kernel) {
            return;
        }

        $manager = self::getContainer()->get(EntityManagerInterface::class);

        if (!$manager instanceof EntityManagerInterface) {
            return;
        }

        $manager->getConnection()->executeStatement(
            'DELETE FROM settings WHERE name = ? OR name LIKE ?',
            [ApplicationSettings::REDUCED_MOTION, 'organisation.%'],
        );
        $manager->clear();
    }
}
