<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Module\Auth\Application\Rbac\PermissionCatalogue;
use App\Module\Plugin\Application\ActivatePlugin;
use App\Module\Plugin\Domain\PluginAlreadyActive;
use App\Module\Plugin\Domain\PluginRepository;
use App\Module\Settings\Contract\SettingsPermissions;
use App\Tests\Module\Plugin\Fixture\PluginAnswers;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Exception as DbalException;
use Doctrine\ORM\EntityManagerInterface;
use RuntimeException;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Die Plugin-Grenze.
 *
 * Ein Plugin ist ein eigener Prozess, der ueber HTTP spricht. Was hier
 * geprueft wird, ist nicht, dass es funktioniert, sondern **dass der Core
 * ohne es vollstaendig ist** und dass es nur bekommt, was jemand ihm
 * ausdruecklich gegeben hat.
 */
final class PluginBoundaryTest extends WebTestCase
{
    use ForgetsRateLimits;
    use SignsIn;

    protected function tearDown(): void
    {
        self::forgetThePlugin();
        self::removeTestUser();

        parent::tearDown();
    }

    /**
     * Ohne aktiviertes Plugin ist die Anwendung vollstaendig.
     *
     * Keine Zwischenueberschrift, kein Menuepunkt, kein Recht im Katalog,
     * keine Seite. **Das ist die wichtigste Zusicherung von allen:** ein
     * Plugin ergaenzt, es traegt nicht — und was ergaenzt, darf fehlen.
     */
    public function testWithoutAnPluginNothingOfItIsThere(): void
    {
        $client = self::signedInWith([SettingsPermissions::VIEW]);

        $client->request('GET', '/');
        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('.ib-nav-plugins', 'Keine Rubrik ohne Plugin');

        $client->request('GET', '/plugins/probe/sheet');
        self::assertResponseStatusCodeSame(404, 'Und keine Seite');

        self::assertNotContains('probe.view', self::catalogueKeys(), 'Und kein Recht im Katalog');
    }

    /**
     * Ein gefundenes Plugin liegt nur da.
     *
     * Es steht auf der Einstellungsseite — sonst raet der Betreiber, ob er es
     * falsch abgelegt hat —, und sonst nirgends.
     */
    public function testAFoundPluginIsVisibleNowhereButInTheSettings(): void
    {
        $client = self::signedInWith([SettingsPermissions::VIEW]);

        $client->request('GET', '/einstellungen/plugins');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Prüfung');
        self::assertSelectorTextContains('body', 'Gefunden');

        $client->request('GET', '/');
        self::assertSelectorNotExists('.ib-nav-plugins');
    }

    /**
     * Ohne die Vertrauensmarke wird nichts aktiviert.
     *
     * Der Knopf steht ohne JavaScript offen da; abgelehnt wird hier. Waere es
     * umgekehrt, haenge die Zusage an einer Datei, die der Browser auch nicht
     * laden koennte.
     */
    public function testActivationWithoutTheTrustMarkDoesNothing(): void
    {
        $client = self::signedInWith([SettingsPermissions::VIEW, SettingsPermissions::EDIT]);

        $client->request('GET', '/einstellungen/plugins/probe');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('main', 'kein Internet', 'Die Vertrauensfrage sagt, was das Plugin im Netz erreicht');

        $form = $client->getCrawler()->filter('main form')->form();
        $client->submit($form);

        self::assertNull(self::plugins()->byName('probe'), 'Nichts aktiviert');
        self::assertSame(0, self::schemaCount(), 'Und kein Schema angelegt');
    }

    /**
     * Aktivieren legt Schema, Tabellen und eine Rolle an — und nur dort.
     *
     * Die Einschraenkung ist der Punkt: kaeme das Plugin an die Tabellen des
     * Cores, waere `/api/v1/` sinnlos und unser internes Schema ueber Nacht
     * oeffentliche Zusage.
     */
    public function testActivationBuildsAStorageThePluginCannotLeave(): void
    {
        self::signedInWith([SettingsPermissions::VIEW]);
        self::activate();

        $connection = self::connection();

        self::assertSame(1, self::schemaCount(), 'Das Schema steht');
        self::assertTrue(
            (bool) $connection->fetchOne("SELECT has_table_privilege('plugin_probe', 'plugin_probe.mirror', 'INSERT')"),
            'Das Plugin schreibt in seine eigene Tabelle',
        );
        self::assertFalse(
            (bool) $connection->fetchOne("SELECT has_table_privilege('plugin_probe', 'public.audit_entry', 'SELECT')"),
            'Aber es kommt nicht an die Tabellen des Cores',
        );
        self::assertFalse(
            (bool) $connection->fetchOne("SELECT has_schema_privilege('plugin_probe', 'plugin_probe', 'CREATE')"),
            'Und es legt sich auch keine neuen an: was es hat, stand im Manifest',
        );
    }

    /**
     * Scheitert die Aktivierung mittendrin, steht danach nichts — und nichts Fremdes fehlt.
     *
     * Die Rolle gibt es hier schon, als haette eine gleichzeitige Aktivierung
     * sie eben angelegt. Das eigene Schema entsteht noch, dann scheitert
     * `CREATE ROLE`: die Transaktion nimmt das Schema zurueck, und die Rolle,
     * die nicht von uns stammt, bleibt, wo sie ist.
     */
    public function testAFailedActivationRollsBackItselfAndNothingElse(): void
    {
        self::signedInWith([SettingsPermissions::VIEW]);
        self::connection()->executeStatement('CREATE ROLE plugin_probe NOLOGIN');

        try {
            self::activate();
            self::fail('Die Aktivierung hätte scheitern müssen');
        } catch (RuntimeException) {
            // So soll es sein.
        }

        self::assertSame(0, self::schemaCount(), 'Kein halbes Schema');
        self::assertNull(self::plugins()->byName('probe'), 'Keine Zeile');
        self::assertTrue(
            (bool) self::connection()->fetchOne("SELECT EXISTS (SELECT FROM pg_roles WHERE rolname = 'plugin_probe')"),
            'Und die fremde Rolle steht noch',
        );
    }

    /**
     * Doppelt aktivieren ist ein Konflikt — und laesst die Anwendung arbeitsfaehig.
     *
     * Eine Ausnahme mitten in der Transaktion schloesse den EntityManager des
     * Requests; alles, was danach noch schreiben will, scheiterte.
     */
    public function testActivatingTwiceIsAConflictThatLeavesPersistenceOpen(): void
    {
        self::signedInWith([SettingsPermissions::VIEW]);
        self::activate();

        try {
            self::activate();
            self::fail('Die zweite Aktivierung hätte abgelehnt werden müssen');
        } catch (PluginAlreadyActive) {
            // So soll es sein.
        }

        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        self::assertTrue($entityManager->isOpen(), 'Der EntityManager ist noch offen');

        $plugin = self::plugins()->byName('probe');
        self::assertNotNull($plugin);
        $plugin->suspend();
        self::plugins()->save($plugin);

        self::assertSame(
            'suspended',
            self::connection()->fetchOne("SELECT state FROM plugin_installation WHERE name = 'probe'"),
            'Und danach wird noch geschrieben',
        );
    }

    /**
     * Eine zweite Aktivierung desselben Plugins wartet, solange die erste laeuft.
     *
     * Die andere Sitzung haelt die Sperre, als waere sie gerade mitten im
     * Anlegen. Statt danebenher ein Schema zu bauen, wartet diese hier — bis
     * die Geduld, die der Test ihr laesst, zu Ende ist.
     */
    public function testASecondActivationWaitsForTheFirst(): void
    {
        self::signedInWith([SettingsPermissions::VIEW]);
        $other = self::secondSession();
        $other->beginTransaction();
        $other->executeQuery("SELECT pg_advisory_xact_lock(hashtext('plugin:probe'))");
        self::connection()->executeStatement("SET lock_timeout = '300ms'");

        try {
            self::activate();
            self::fail('Die Aktivierung hätte warten müssen');
        } catch (DbalException $waited) {
            self::assertStringContainsString('lock timeout', $waited->getMessage());
        } finally {
            self::connection()->executeStatement('RESET lock_timeout');
            $other->rollBack();
            $other->close();
        }

        self::assertSame(0, self::schemaCount(), 'Kein Schema neben der ersten Aktivierung');
    }

    /** Das Recht des Plugins steht danach im selben Katalog wie jedes andere. */
    public function testAnActivatedPluginBringsItsPermissionIntoTheCatalogue(): void
    {
        self::signedInWith([SettingsPermissions::VIEW]);
        self::activate();

        self::assertContains('probe.view', self::catalogueKeys());
    }

    /**
     * Der Menuepunkt erscheint — aber nur fuer den, der sein Recht hat.
     *
     * **Die Reihenfolge ist Teil der Zusicherung.** Der Client entsteht vor
     * der Aktivierung, und dabei wird geschrieben; wuerde sich die Anwendung
     * den Stand der Plugins dabei merken und behalten, staende der neue
     * Menuepunkt bis zum naechsten Aufruf nicht da. Genau das ist einmal
     * passiert.
     */
    public function testTheMenuEntryFollowsItsOwnPermission(): void
    {
        $client = self::signedInWith(['probe.view']);
        self::activate();

        $client->request('GET', '/');
        self::assertSelectorExists('.ib-nav-plugins');
        self::assertSelectorTextContains('.ib-nav-plugins', 'Prüfblatt');
    }

    /** Ohne sein Recht steht da nichts — auch nicht die Rubrik. */
    public function testWithoutItsPermissionTheEntryStaysAway(): void
    {
        $client = self::signedInWith([SettingsPermissions::VIEW]);
        self::activate();

        $client->request('GET', '/');
        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('.ib-nav-plugins');
    }

    /** Die Seite kommt vom Plugin und steht in unserer Shell. */
    public function testTheProxyShowsThePluginsPageInsideTheShell(): void
    {
        $client = self::signedInWith(['probe.view']);
        self::activate();

        $client->request('GET', '/plugins/probe/sheet');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('main', 'Prüfseite');
        self::assertSelectorExists('.ib-sidebar', 'Und die Navigation steht daneben');
    }

    /**
     * Ein Pfad, der in keinem Menuepunkt steht, gibt es nicht.
     *
     * Sonst lieferte ein Plugin Seiten aus, die niemand bestaetigt hat — und
     * der Core riefe eine Adresse ab, die aus der Adresszeile kommt.
     */
    public function testAPathOutsideTheManifestIsNotServed(): void
    {
        $client = self::signedInWith(['probe.view']);
        self::activate();

        $client->request('GET', '/plugins/probe/hidden');

        self::assertResponseStatusCodeSame(404);
    }

    /**
     * Ein Pfad, den der HTTP-Client unterwegs umdeuten wuerde, kommt gar
     * nicht erst an. Geprueft wird das Recht von `/sheet`, angekommen waere
     * `/webhook` — ohne Menuepunkt und ohne Recht.
     */
    public function testAPathThatWouldChangeOnTheWayIsNotServed(): void
    {
        $client = self::signedInWith(['probe.view']);
        self::activate();

        foreach (['/plugins/probe/sheet/..%2Fwebhook', '/plugins/probe/sheet/%2E%2E/webhook', '/plugins/probe/sheet//x'] as $address) {
            $client->request('GET', $address);
            self::assertResponseStatusCodeSame(404, $address);
        }
    }

    /**
     * Ein totes Plugin nimmt die Anwendung nicht mit.
     *
     * Kein Stacktrace, keine Ausnahme, ein ruhiger Satz — und die Shell steht.
     */
    public function testASilentPluginLeavesTheApplicationStanding(): void
    {
        $client = self::signedInWith(['probe.view']);
        self::activate();

        $answers = self::getContainer()->get(PluginAnswers::class);
        self::assertInstanceOf(PluginAnswers::class, $answers);
        $answers->silent = true;

        $client->request('GET', '/plugins/probe/sheet');

        self::assertResponseStatusCodeSame(502);
        self::assertSelectorTextContains('main', 'antwortet nicht');
        self::assertSelectorExists('.ib-sidebar');
    }

    /** Entfernen nimmt das Schema mit — sonst raeumt es nie jemand auf. */
    public function testRemovingTakesTheStorageWithIt(): void
    {
        $client = self::signedInWith([SettingsPermissions::VIEW, SettingsPermissions::EDIT]);
        self::activate();
        self::assertSame(1, self::schemaCount());

        $client->request('GET', '/einstellungen/plugins');
        $client->submit($client->getCrawler()->filter('dialog form')->form());

        self::assertNull(self::plugins()->byName('probe'));
        self::assertSame(0, self::schemaCount());
    }

    protected static function testEmail(): string
    {
        return 'plugin@example.org';
    }

    /**
     * Aktivieren wie die Oberflaeche es tut — nur ohne Klick.
     *
     * Ueber den Anwendungsfall und nicht ueber ein eingesetztes Objekt: was
     * dabei alles entsteht, ist gerade der Gegenstand der Pruefung.
     */
    private static function activate(): void
    {
        $activate = self::getContainer()->get(ActivatePlugin::class);
        self::assertInstanceOf(ActivatePlugin::class, $activate);

        $activate('probe', 'Prüfer');
    }

    private static function plugins(): PluginRepository
    {
        $plugins = self::getContainer()->get(PluginRepository::class);
        self::assertInstanceOf(PluginRepository::class, $plugins);

        return $plugins;
    }

    /**
     * @return list<string>
     */
    private static function catalogueKeys(): array
    {
        $catalogue = self::getContainer()->get(PermissionCatalogue::class);
        self::assertInstanceOf(PermissionCatalogue::class, $catalogue);

        return array_map(static fn (object $permission): string => $permission->key(), $catalogue->all());
    }

    private static function schemaCount(): int
    {
        $count = self::connection()->fetchOne(
            "SELECT count(*) FROM information_schema.schemata WHERE schema_name LIKE 'plugin\_%'",
        );

        return is_numeric($count) ? (int) $count : 0;
    }

    /** Eine eigene Datenbanksitzung — so, wie ein zweiter Request sie haette. */
    private static function secondSession(): Connection
    {
        $params = self::connection()->getParams();
        $password = $params['password'] ?? null;

        return DriverManager::getConnection([
            'driver' => 'pdo_pgsql',
            'host' => \is_string($params['host'] ?? null) ? $params['host'] : 'localhost',
            'port' => \is_int($params['port'] ?? null) ? $params['port'] : 5432,
            'user' => \is_string($params['user'] ?? null) ? $params['user'] : '',
            'password' => \is_string($password) ? $password : '',
            'dbname' => \is_string($params['dbname'] ?? null) ? $params['dbname'] : '',
        ]);
    }

    private static function connection(): Connection
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);

        return $entityManager->getConnection();
    }

    /** Kein Test laesst ein Schema stehen — der naechste faende es vor. */
    private static function forgetThePlugin(): void
    {
        if (null === self::$kernel) {
            return;
        }

        $connection = self::connection();
        $connection->executeStatement("DELETE FROM plugin_installation WHERE name = 'probe'");
        $connection->executeStatement('DROP SCHEMA IF EXISTS plugin_probe CASCADE');

        // REASSIGN OWNED BY kennt kein IF EXISTS, und nicht jeder Test legt
        // die Rolle an — ohne die Abfrage risse das Aufraeumen jeden Test
        // mit, der ohne Plugin auskommt.
        $connection->executeStatement(<<<'SQL'
            DO $$ BEGIN
                IF EXISTS (SELECT FROM pg_roles WHERE rolname = 'plugin_probe') THEN
                    REASSIGN OWNED BY plugin_probe TO CURRENT_USER;
                    DROP OWNED BY plugin_probe;
                    DROP ROLE plugin_probe;
                END IF;
            END $$;
            SQL);
    }
}
