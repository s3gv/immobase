<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Module\Finance\Domain\CostItem;
use App\Module\Finance\Domain\CostItemFilter;
use App\Module\Finance\Domain\CostItemRepository;
use App\Module\Finance\Domain\CostItemYear;
use App\Module\Plugin\Application\ActivatePlugin;
use App\Module\Plugin\Application\DeliverEvents;
use App\Module\Plugin\Application\IssueToken;
use App\Module\Plugin\Application\SetPluginState;
use App\Module\Plugin\Domain\Delivery;
use App\Module\Plugin\Domain\DeliveryRepository;
use App\Module\Plugin\Domain\PluginState;
use App\Shared\Money\Money;
use App\Shared\Ui\Page;
use App\Shared\Ui\Sort;
use App\Tests\Module\Plugin\Fixture\PluginAnswers;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Ausgehende Ereignisse.
 *
 * Ein Plugin soll aktuell bleiben, ohne dauernd zu fragen. Die Zusicherungen
 * sind: es erfaehrt von dem, was es lesen darf — **und von nichts sonst** —,
 * die Meldung traegt keine Fachdaten, und ein totes Plugin staut sich, statt
 * etwas mitzunehmen.
 */
final class PluginWebhookTest extends WebTestCase
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
     * Ein Schreibvorgang in einem beliebigen Modul landet in der Ablage.
     *
     * Kein Modul tut etwas dafuer: das Signal kommt von Doctrine. Deshalb
     * wird hier auch kein Modul geprueft, sondern ob ueberhaupt geschrieben
     * werden kann, ohne dass ein angemeldetes Plugin davon erfaehrt.
     */
    public function testAWriteReachesTheOutbox(): void
    {
        self::createClient();
        self::activate();
        self::buildTheProperty();

        $waiting = self::waiting();
        $events = array_map(static fn (Delivery $delivery): string => $delivery->event, $waiting);

        self::assertNotSame([], $waiting, 'Es liegt etwas in der Ablage');
        self::assertSame('probe', $waiting[0]->plugin);
        self::assertContains('properties.created', $events);
    }

    /**
     * Die Nutzlast traegt den Hinweis und keine Fachdaten.
     *
     * Mit Daten waere sie eine zweite Stelle, an der Berechtigungen geprueft
     * werden muessten — und damit eine zweite, an der man sie vergisst.
     */
    public function testThePayloadCarriesNothingButTheHint(): void
    {
        self::createClient();
        self::activate();
        self::buildTheProperty();

        $waiting = self::waiting();
        self::assertArrayHasKey(0, $waiting);

        $payload = json_decode($waiting[0]->payload, true);

        self::assertIsArray($payload);
        self::assertSame(['event', 'resource', 'id', 'at'], array_keys($payload));
        self::assertArrayHasKey('resource', $payload);
        self::assertContains($payload['resource'], ['properties', 'units']);
    }

    /**
     * Was das Plugin nicht lesen darf, erfaehrt es auch nicht.
     *
     * Das Prüf-Plugin darf Objekte lesen und sonst nichts. Ein Ereignis ueber
     * einen Kontakt waere die Auskunft, dass es ihn gibt — durch die
     * Hintertuer und ohne dass jemand sie freigegeben haette.
     */
    public function testItHearsNothingAboutWhatItMayNotRead(): void
    {
        self::createClient();
        self::activate();
        self::buildTheProperty();

        // Das Bauwerk der Tests legt Objekte, Einheiten **und** Kontakte an,
        // und das Prüf-Plugin hat „*.*" abonniert — es will also alles.
        // Bekommen darf es trotzdem nur, was es lesen darf: Objekte und
        // Finanzen ja, Kontakte nicht. Von denen erfährt es nichts, auch
        // nicht, dass es sie gibt. Abonniert **und** freigegeben, nicht eines
        // von beiden.
        $resources = array_map(
            static fn (Delivery $delivery): string => explode('.', $delivery->event)[0],
            self::waiting(),
        );

        self::assertNotSame([], $resources);
        self::assertNotContains('parties', $resources, 'Kontakte sind nicht freigegeben');
        self::assertNotContains('tenancies', $resources, 'Mietverhältnisse ebenso wenig');
        self::assertSame([], array_diff($resources, ['properties', 'units', 'costs', 'cost-kinds', 'loans', 'reserve-movements']));
    }

    /**
     * Auch ein Teil meldet sein Ganzes.
     *
     * Ein Jahreswert ist keine eigene Ressource — er steht in der
     * Kostenposition. Wer ihn aendert, aendert damit das, was unter
     * `/api/v1/costs/<id>` steht, und genau das ist die Aenderung, fuer die
     * es eine Kostenauswertung gibt. Gemeldet wird die Kennung des Ganzen,
     * denn ihr folgt das Plugin.
     */
    public function testAChangedPartReportsItsWhole(): void
    {
        self::createClient();
        self::activate();
        self::buildTheProperty();
        self::forgetWhatIsWaiting();

        $costs = self::getContainer()->get(CostItemRepository::class);
        self::assertInstanceOf(CostItemRepository::class, $costs);

        $item = $costs->matching(CostItemFilter::none(), Page::of(1, 1), Sort::by('number'))[0] ?? null;
        self::assertInstanceOf(CostItem::class, $item);

        $year = $item->years()->all()[0] ?? null;
        self::assertInstanceOf(CostItemYear::class, $year);

        $year->cost(Money::fromCents(999_00), $year->mode());
        $costs->save($item);

        $waiting = self::waiting();
        self::assertNotSame([], $waiting, 'Der geänderte Jahreswert wird gemeldet');

        $payload = json_decode($waiting[0]->payload, true);
        self::assertIsArray($payload);
        self::assertArrayHasKey('event', $payload);
        self::assertArrayHasKey('id', $payload);
        self::assertSame('costs.updated', $payload['event']);
        self::assertSame($item->id(), $payload['id'], 'Und zwar unter der Kennung der Kostenposition');
    }

    /** Ohne aktiviertes Plugin entsteht gar nichts. */
    public function testWithoutAnPluginNothingIsQueued(): void
    {
        self::createClient();
        self::buildTheProperty();

        self::assertSame([], self::waiting());
    }

    /** Zugestellt heisst weg aus der Ablage. */
    public function testADeliveredEventLeavesTheOutbox(): void
    {
        self::createClient();
        self::activate();
        self::buildTheProperty();
        self::assertNotSame([], self::waiting());

        self::assertGreaterThan(0, self::deliver(), 'Es ist etwas angekommen');
        self::assertSame([], self::waiting(), 'Und danach ist die Ablage leer');
    }

    /**
     * Ein totes Plugin staut sich und nimmt nichts mit.
     *
     * Die Zeile bleibt liegen und wird spaeter noch einmal versucht; der Core
     * merkt davon nichts.
     */
    public function testASilentPluginOnlyDelaysItsOwnEvents(): void
    {
        self::createClient();
        self::activate();
        self::buildTheProperty();

        $answers = self::getContainer()->get(PluginAnswers::class);
        self::assertInstanceOf(PluginAnswers::class, $answers);
        $answers->silent = true;

        self::assertSame(0, self::deliver(), 'Nichts angekommen');

        $waiting = self::deliveries()->due(new DateTimeImmutable('+1 year'), 50);
        self::assertNotSame([], $waiting, 'Die Zeile liegt noch da');
        self::assertSame(1, $waiting[0]->attempts, 'Und sie zählt ihre Versuche');
        self::assertNotSame('', $waiting[0]->lastError, 'Mit Vermerk, woran es lag');
    }

    /** Ein ausgesetztes Plugin bekommt nichts — auch nicht nachtraeglich. */
    public function testASuspendedPluginLosesWhatWasWaiting(): void
    {
        self::createClient();
        self::activate();
        self::buildTheProperty();
        self::assertNotSame([], self::waiting());

        $suspend = self::getContainer()->get(SetPluginState::class);
        self::assertInstanceOf(SetPluginState::class, $suspend);
        $suspend('probe', PluginState::Suspended);

        self::assertSame(0, self::deliver(), 'Nichts geht mehr hinaus');
        self::assertSame([], self::waiting(), 'Und nichts staut sich auf');
    }

    protected static function testEmail(): string
    {
        return 'webhook@example.org';
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

    private static function deliver(): int
    {
        $deliver = self::getContainer()->get(DeliverEvents::class);
        self::assertInstanceOf(DeliverEvents::class, $deliver);

        return $deliver();
    }

    /**
     * @return list<Delivery>
     */
    private static function waiting(): array
    {
        return self::deliveries()->due(new DateTimeImmutable('+1 minute'), 50);
    }

    /** Die Zeilen des Aufbaus interessieren hier nicht — nur die danach. */
    private static function forgetWhatIsWaiting(): void
    {
        foreach (self::waiting() as $delivery) {
            self::deliveries()->forget($delivery->id);
        }
    }

    private static function deliveries(): DeliveryRepository
    {
        $deliveries = self::getContainer()->get(DeliveryRepository::class);
        self::assertInstanceOf(DeliveryRepository::class, $deliveries);

        return $deliveries;
    }

    private static function forgetThePlugin(): void
    {
        if (null === self::$kernel) {
            return;
        }

        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);

        $connection = $entityManager->getConnection();
        $connection->executeStatement('DELETE FROM plugin_delivery');
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
