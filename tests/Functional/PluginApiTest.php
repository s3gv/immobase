<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Module\Api\Application\ResourceCatalogue;
use App\Module\Plugin\Application\ActivatePlugin;
use App\Module\Plugin\Application\IssueToken;
use App\Module\Plugin\Application\SetPluginState;
use App\Module\Plugin\Domain\PluginState;
use App\Module\Plugin\Domain\TileRepository;
use App\Shared\Api\PublishesResource;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Die Datenschnittstelle `/api/v1/`.
 *
 * Sie ist die einzige Stelle, an der Fachdaten den Core verlassen. Was hier
 * geprueft wird, ist deshalb nicht, dass sie Daten liefert, sondern **dass
 * sie ohne Ausweis keine liefert** — und zwar bei jeder Ressource, auch bei
 * der, die es morgen erst gibt.
 */
final class PluginApiTest extends WebTestCase
{
    use BuildsABillableProperty;
    use ForgetsRateLimits;
    use SignsIn;

    protected function tearDown(): void
    {
        self::removeTheProperty();
        self::forgetThePlugin();
        self::removeTestUser();

        parent::tearDown();
    }

    /**
     * Ohne Token gibt keine Ressource etwas heraus.
     *
     * Der Katalog wird durchgegangen und nicht aufgezaehlt: eine Liste hier
     * waere beim naechsten Modul unvollstaendig, und genau die neue Ressource
     * ist die, bei der jemand die Pruefung vergisst.
     */
    public function testNotOneResourceAnswersWithoutAToken(): void
    {
        $client = self::createClient();
        $catalogue = self::getContainer()->get(ResourceCatalogue::class);
        self::assertInstanceOf(ResourceCatalogue::class, $catalogue);

        $resources = $catalogue->all();
        self::assertNotSame([], $resources, 'Es gibt überhaupt Ressourcen');

        foreach ($resources as $resource) {
            self::assertInstanceOf(PublishesResource::class, $resource);

            $client->request('GET', '/api/v1/'.$resource->name());

            self::assertResponseStatusCodeSame(401, $resource->name().' ohne Token');
            self::assertSame('application/json', self::contentTypeOf($client), 'Und als JSON, nicht als Anmeldeseite');
        }
    }

    /** Mit Token und Freigabe kommen Daten — flach, seitenweise, mit Summe. */
    public function testWithATokenTheAllowedResourceAnswers(): void
    {
        $client = self::createClient();
        self::buildTheProperty();
        $token = self::activate();

        $client->request('GET', '/api/v1/properties', server: self::bearer($token));

        self::assertResponseIsSuccessful();
        $body = self::jsonOf($client);

        self::assertArrayHasKey('data', $body);
        self::assertArrayHasKey('page', $body);
        self::assertArrayHasKey('total', $body);
        self::assertIsArray($body['data']);
        self::assertNotSame([], $body['data']);
        self::assertSame(1, $body['page']);
        self::assertGreaterThan(0, $body['total']);

        self::assertArrayHasKey(0, $body['data']);
        $first = $body['data'][0];
        self::assertIsArray($first);
        self::assertArrayHasKey('id', $first);
        self::assertArrayHasKey('address', $first);
    }

    /**
     * Was nicht freigegeben ist, bleibt zu — auch mit gueltigem Token.
     *
     * Das Prüf-Plugin darf Objekte lesen und sonst nichts. Die Freigabe ist
     * das, was jemand beim Aktivieren bestaetigt hat, und nicht das, was das
     * Plugin gerne haette.
     */
    public function testATokenOnlyOpensWhatWasConfirmed(): void
    {
        $client = self::createClient();
        $token = self::activate();

        // Das Prüf-Plugin darf Objekte und Finanzen lesen — Stammdaten nicht.
        $client->request('GET', '/api/v1/parties', server: self::bearer($token));

        self::assertResponseStatusCodeSame(403);
        self::assertSame('forbidden', self::errorCodeOf($client));
    }

    /** Ein ausgesetztes Plugin ist auch mit seinem Token keines mehr. */
    public function testASuspendedPluginIsNoOneAnymore(): void
    {
        $client = self::createClient();
        $token = self::activate();

        $suspend = self::getContainer()->get(SetPluginState::class);
        self::assertInstanceOf(SetPluginState::class, $suspend);
        $suspend('probe', PluginState::Suspended);

        $client->request('GET', '/api/v1/properties', server: self::bearer($token));

        self::assertResponseStatusCodeSame(401);
    }

    /** Ein erfundenes Token ist keines. */
    public function testAnInventedTokenIsNone(): void
    {
        $client = self::createClient();
        self::activate();

        $client->request('GET', '/api/v1/properties', server: self::bearer('ib_'.str_repeat('a', 64)));

        self::assertResponseStatusCodeSame(401);
    }

    /** Eine Ressource, die es nicht gibt, gibt es nicht. */
    public function testAnUnknownResourceIsNotFound(): void
    {
        $client = self::createClient();
        $token = self::activate();

        $client->request('GET', '/api/v1/einhoerner', server: self::bearer($token));

        self::assertResponseStatusCodeSame(404);
        self::assertSame('unknown_resource', self::errorCodeOf($client));
    }

    /** Ein Plugin erfaehrt ueber sich, was es wissen muss — und nur ueber sich. */
    public function testAnPluginCanAskWhoItIsAndWhereItsStorageIs(): void
    {
        $client = self::createClient();
        $token = self::activate();

        $client->request('GET', '/api/v1/plugins/self', server: self::bearer($token));
        self::assertResponseIsSuccessful();

        $body = self::jsonOf($client);
        self::assertArrayHasKey('data', $body);
        self::assertIsArray($body['data']);
        self::assertArrayHasKey('name', $body['data']);
        self::assertArrayHasKey('reads', $body['data']);
        self::assertSame('probe', $body['data']['name']);
        self::assertSame(['properties.view', 'finance.view'], $body['data']['reads']);

        $client->request('GET', '/api/v1/plugins/self/storage', server: self::bearer($token));
        self::assertResponseIsSuccessful();

        $storage = self::jsonOf($client);
        self::assertArrayHasKey('data', $storage);
        self::assertIsArray($storage['data']);
        self::assertArrayHasKey('schema', $storage['data']);
        self::assertArrayHasKey('dsn', $storage['data']);
        self::assertSame('plugin_probe', $storage['data']['schema']);
        self::assertIsString($storage['data']['dsn']);
        self::assertStringContainsString('plugin_probe', $storage['data']['dsn'], 'Und sie führt in sein eigenes Schema');
    }

    /**
     * Kacheln kommen vom Plugin und stehen danach auf der Uebersicht.
     *
     * Geschoben und nicht geholt: die Uebersicht fragt beim Aufbau niemanden.
     * Was nicht passt — ein erfundener Ton, ein Pfad nach draussen —, faellt
     * dabei raus, statt die ganze Ablieferung abzulehnen.
     */
    public function testAnPluginPutsTilesOnTheOverview(): void
    {
        $client = self::createClient();
        $token = self::activate();

        $client->request('PUT', '/api/v1/plugins/self/tiles', server: [
            ...self::bearer($token),
            'CONTENT_TYPE' => 'application/json',
        ], content: (string) json_encode([
            ['key' => 'cost_trend', 'label' => ['de' => 'Kosten ggü. Vorjahr'], 'value' => '+4,2 %', 'tone' => 'warning', 'path' => '/sheet'],
            ['key' => 'nonsense', 'label' => ['de' => 'Unfug'], 'value' => 'x', 'tone' => 'pink', 'path' => '//example.org'],
        ]));

        self::assertResponseIsSuccessful();

        $tiles = self::getContainer()->get(TileRepository::class);
        self::assertInstanceOf(TileRepository::class, $tiles);

        $fresh = $tiles->fresh(new DateTimeImmutable('-1 hour'));
        self::assertCount(2, $fresh);

        $byKey = [];

        foreach ($fresh as $tile) {
            $byKey[$tile->key] = $tile;
        }

        self::assertArrayHasKey('cost_trend', $byKey);
        self::assertArrayHasKey('nonsense', $byKey);
        self::assertSame('+4,2 %', $byKey['cost_trend']->value);
        self::assertSame('warning', $byKey['cost_trend']->tone);
        self::assertSame('/sheet', $byKey['cost_trend']->path);
        self::assertSame('neutral', $byKey['nonsense']->tone, 'Ein erfundener Ton wird still zu „neutral"');
        self::assertSame('', $byKey['nonsense']->path, 'Und ein Pfad nach draußen zu keinem Link');
    }

    /** Ohne Token auch das nicht. */
    public function testTheSelfPageNeedsATokenToo(): void
    {
        $client = self::createClient();

        $client->request('GET', '/api/v1/plugins/self/storage');

        self::assertResponseStatusCodeSame(401);
    }

    protected static function testEmail(): string
    {
        return 'api@example.org';
    }

    /**
     * Aktivieren und das Token ausstellen, das der Aufseher dem Prozess beim
     * Start mitgaebe.
     */
    private static function activate(): string
    {
        $activate = self::getContainer()->get(ActivatePlugin::class);
        self::assertInstanceOf(ActivatePlugin::class, $activate);
        $activate('probe', 'Prüfer');

        $issue = self::getContainer()->get(IssueToken::class);
        self::assertInstanceOf(IssueToken::class, $issue);

        return $issue('probe');
    }

    /**
     * @return array<string, string>
     */
    private static function bearer(string $token): array
    {
        return ['HTTP_AUTHORIZATION' => 'Bearer '.$token];
    }

    /**
     * @return array<string, mixed>
     */
    private static function jsonOf(KernelBrowser $client): array
    {
        $body = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($body);

        /** @var array<string, mixed> $body */
        return $body;
    }

    private static function errorCodeOf(KernelBrowser $client): string
    {
        $body = self::jsonOf($client);
        self::assertArrayHasKey('error', $body);
        self::assertIsArray($body['error']);
        self::assertArrayHasKey('code', $body['error']);
        self::assertIsString($body['error']['code']);

        return $body['error']['code'];
    }

    private static function contentTypeOf(KernelBrowser $client): string
    {
        $type = (string) $client->getResponse()->headers->get('Content-Type');

        return trim(explode(';', $type)[0]);
    }

    private static function forgetThePlugin(): void
    {
        if (null === self::$kernel) {
            return;
        }

        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);

        $connection = $entityManager->getConnection();
        $connection->executeStatement('DELETE FROM plugin_tile');
        $connection->executeStatement("DELETE FROM plugin_installation WHERE name = 'probe'");
        $connection->executeStatement('DROP SCHEMA IF EXISTS plugin_probe CASCADE');
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
