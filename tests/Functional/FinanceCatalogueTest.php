<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Module\Finance\Application\MaintainDistributionKeys;
use App\Module\Finance\Domain\CostItem;
use App\Module\Finance\Domain\CostItemRepository;
use App\Module\Finance\Domain\CostKind;
use App\Module\Finance\Domain\CostKindRepository;
use App\Module\Finance\Domain\DistributionKey;
use App\Module\Finance\Domain\DistributionKeyIsInUse;
use App\Module\Finance\Domain\DistributionKeyKind;
use App\Module\Finance\Domain\DistributionKeyRepository;
use App\Module\Finance\Domain\FinancePermissions;
use App\Module\Finance\Domain\RecordedInTheMeantime;
use App\Module\Finance\Domain\UnitBelongsElsewhere;
use App\Module\Finance\UserInterface\Controller\DistributionKeyController;
use App\Module\Property\Domain\ManagementMode;
use App\Module\Property\Domain\ManagementModes;
use App\Module\Property\Domain\Property;
use App\Module\Property\Domain\PropertyRepository;
use App\Module\Property\Domain\Unit;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Kostenarten und Verteilerschluessel: die Sprache der Abrechnung.
 *
 * Beide werden mitgeliefert. Was mitkommt, bleibt — es ist die gemeinsame
 * Sprache, in der spaeter jede Abrechnung gruppiert, und wer eine davon
 * loeschte, haette Positionen, die auf nichts mehr zeigen.
 */
final class FinanceCatalogueTest extends WebTestCase
{
    use ForgetsRateLimits;
    use SignsIn;
    use UsesASecondConnection;

    private const string OWN_KIND = 'Prüfkostenart';

    private const string OWN_KEY = 'Prüfschlüssel';

    private const string PROPERTY = 'Katalogobjekt Prüfung';

    private const string OTHER_PROPERTY = 'Fremdes Katalogobjekt Prüfung';

    protected function tearDown(): void
    {
        self::cleanUp();
        self::removeTestUser();

        parent::tearDown();
    }

    /** Die siebzehn Positionen der BetrKV stehen von Anfang an da. */
    public function testTheCatalogueIsDeliveredWithTheApplication(): void
    {
        self::bootKernel();
        $names = array_map(static fn (CostKind $kind): string => $kind->name(), self::kinds()->all());

        self::assertContains('Grundsteuer', $names);
        self::assertContains('Sonstige Betriebskosten', $names);
        self::assertContains('Verwaltervergütung', $names);
    }

    public function testWhatIsDeliveredIsMarkedAsApportionableOrNot(): void
    {
        self::bootKernel();
        $kinds = [];

        foreach (self::kinds()->all() as $kind) {
            $kinds[$kind->name()] = $kind->isApportionable();
        }

        self::assertTrue($kinds['Grundsteuer'] ?? false, 'Grundsteuer ist umlagefähig');
        self::assertFalse($kinds['Verwaltervergütung'] ?? true, 'Das Verwalterhonorar ist es nicht');
    }

    /** Eine eigene Art steht hinter den mitgelieferten. */
    public function testAnOwnKindIsAddedBehindTheCatalogue(): void
    {
        $client = self::editor();

        self::post($client, '/finanzen/kostenarten', '/finanzen/kostenarten', [
            'name' => self::OWN_KIND,
            'apportionable' => '0',
        ]);

        $own = self::kindByName(self::OWN_KIND);
        self::assertNotNull($own);
        self::assertFalse($own->isApportionable());
        self::assertFalse($own->isSystem());
    }

    /**
     * Mitgelieferte Arten bleiben — auch an der Oberflaeche vorbei.
     */
    public function testACatalogueKindIsNotDeleted(): void
    {
        $client = self::editor();
        $kind = self::kindByName('Grundsteuer');
        self::assertNotNull($kind);

        self::post($client, '/finanzen/kostenarten/'.$kind->id(), '/finanzen/kostenarten', [
            'name' => 'Grundsteuer',
            'apportionable' => '1',
            'remove' => '1',
        ]);

        self::assertNotNull(self::kindByName('Grundsteuer'), 'Die Kostenart steht noch da');
    }

    /**
     * Ohne gewaehltes Objekt gibt es einen Hinweis, keine Fehlerseite.
     *
     * Das Auswahlfeld beginnt bei „Objekt wählen", und wer es so laesst,
     * schickt ein leeres Feld mit. Als Zahl gelesen beantwortete Symfony das
     * mit 400 — der haeufigste Fehlgriff auf dieser Seite fuehrte damit
     * zuverlaessig auf eine Fehlerseite.
     */
    public function testAKeyWithoutAPropertyIsRefusedWithAHint(): void
    {
        $client = self::editor();

        self::post($client, '/finanzen/verteilerschluessel/neu', '/finanzen/verteilerschluessel?abschnitt=neu', [
            'name' => self::OWN_KEY,
            'property' => '',
            'kind' => 'fixed',
        ]);

        self::assertResponseRedirects('/finanzen/verteilerschluessel');
    }

    /**
     * Ohne Schreibrecht steht dieselbe Liste als Tabelle da.
     *
     * Die bearbeitbare Fassung ist ein Raster aus Formularen; die
     * Nur-Lesen-Fassung ist eine gewoehnliche Tabelle. Zwei Zweige heisst:
     * beide muessen sich zeichnen lassen.
     */
    public function testWithoutEditRightsTheCatalogueIsAPlainTable(): void
    {
        $client = self::signedInWith([FinancePermissions::VIEW]);

        $client->request('GET', '/finanzen/kostenarten');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('.ib-table', 'Eine Tabelle');
        self::assertSelectorNotExists('.ib-catalogue', 'Und keine Formularzeilen');
        self::assertStringContainsString('Grundsteuer', (string) $client->getResponse()->getContent());
    }

    /**
     * „Nach Verbrauch" ist ein Systemschluessel und keiner je Objekt.
     *
     * Er traegt selbst keine Werte — die Mengen stehen an der
     * Kostenposition, je Einheit und je Wirtschaftsjahr. Einen davon je Haus
     * anzulegen hiesse, dieselbe leere Huelse noch einmal zu bauen.
     */
    public function testMeteringIsASystemKeyLikeAnyOther(): void
    {
        self::bootKernel();

        $metered = array_values(array_filter(
            self::keys()->all(),
            static fn (DistributionKey $key): bool => $key->kind()->isMetered(),
        ));

        self::assertCount(1, $metered, 'Es gibt genau einen');
        self::assertTrue($metered[0]->isSystem());
        self::assertNull($metered[0]->propertyId(), 'Und er gehört keinem Objekt');
    }

    /**
     * Ein eigener Schluessel traegt immer feste Anteile.
     *
     * Die berechneten stehen schon im System, und die Erfassung ist der eine
     * Systemschluessel — es bleibt nichts zu waehlen, also wird auch nichts
     * gefragt. Ein mitgeschicktes „metered" aendert daran nichts.
     */
    public function testAnOwnKeyAlwaysCarriesFixedShares(): void
    {
        $client = self::editor();
        $property = self::givenProperty();

        self::post($client, '/finanzen/verteilerschluessel/neu', '/finanzen/verteilerschluessel?abschnitt=neu', [
            'name' => self::OWN_KEY,
            'property' => (string) $property->number(),
            'kind' => 'metered',
        ]);

        $own = self::keyByName(self::OWN_KEY);
        self::assertNotNull($own);
        self::assertTrue($own->kind()->needsShares());
    }

    /**
     * Jeder Abschnitt der Schluesselseite zeichnet sich.
     *
     * Twig prueft beim Linten nur die Syntax, nicht die Pfade: ein
     * umgezogener Wert faellt erst auf, wenn die Seite jemand oeffnet.
     */
    public function testEverySectionOfTheKeyPageRenders(): void
    {
        $client = self::editor();

        foreach (DistributionKeyController::SECTIONS as $section) {
            $client->request('GET', '/finanzen/verteilerschluessel?abschnitt='.$section);
            self::assertResponseIsSuccessful('Abschnitt '.$section);
        }
    }

    /**
     * Anteile lassen sich aendern, ohne in den eindeutigen Index zu laufen.
     *
     * Entfernen und Anlegen in derselben Runde geht nicht: Doctrine fuegt
     * erst ein und loescht dann, und `(key_id, unit_id)` gibt es nur einmal.
     * Derselbe Fallstrick wie bei den Verbrauchswerten.
     */
    public function testSharesCanBeChangedTwice(): void
    {
        self::bootKernel();
        $property = self::givenProperty();
        $key = self::maintain()->add($property->id(), self::OWN_KEY, DistributionKeyKind::Fixed);
        $units = $property->units();
        self::assertNotSame([], $units);
        $unit = $units[0];

        self::maintain()->hold($key, [$unit->id() => '60']);
        self::maintain()->hold($key, [$unit->id() => '40']);

        $shares = self::keyByName(self::OWN_KEY)?->shares() ?? [];
        self::assertCount(1, $shares, 'Der Anteil steht einmal da');
        self::assertSame('40', $shares[0]->share(), 'Und trägt den neuen Wert');
    }

    /**
     * Die Summe der Anteile ist exakt.
     *
     * Sie wurde in der Vorlage aufaddiert und war damit ein `float`: beim
     * Anzeigen machte PHP aus 3,5 eine 3. Auf der Seite stand die falsche
     * Summe, und niemandem waere es aufgefallen.
     */
    public function testTheSumOfSharesKeepsItsFraction(): void
    {
        $client = self::editor();
        $property = self::givenProperty();
        $key = self::maintain()->add($property->id(), self::OWN_KEY, DistributionKeyKind::Fixed);
        $units = $property->units();
        self::assertNotSame([], $units);

        self::maintain()->hold($key, [$units[0]->id() => '2,5']);

        self::assertSame('2.5', self::keyByName(self::OWN_KEY)?->totalShare());

        $client->request('GET', '/finanzen/verteilerschluessel/'.$key->id());

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('2,5<', (string) $client->getResponse()->getContent());
    }

    /**
     * Ein Schluessel, den eine Kostenposition benutzt, wird nicht geloescht.
     *
     * Der Fremdschluessel haelt zwar, aber als Datenbankfehler — und ein
     * 500er ist keine Antwort auf eine Frage, die man verstehen kann.
     */
    public function testAKeyInUseIsRefusedBeforeTheDatabaseRefusesIt(): void
    {
        self::bootKernel();
        $property = self::givenProperty();
        $key = self::maintain()->add($property->id(), self::OWN_KEY, DistributionKeyKind::Fixed);
        self::givenItemUsing($property, $key);

        $this->expectException(DistributionKeyIsInUse::class);

        self::maintain()->drop($key);
    }

    /**
     * Ein Schluessel verteilt auf die Einheiten seines Objekts.
     *
     * Die Oberflaeche bietet nur die eigenen an — aber ein abgeschicktes
     * Formular ist Eingabe und keine Zusicherung. Ein untergeschobener
     * fremder Anteil stuende auf der Seite des Schluessels nirgends und
     * verteilte trotzdem mit.
     */
    public function testAShareForAForeignUnitIsRefused(): void
    {
        self::bootKernel();
        $property = self::givenProperty();
        $key = self::maintain()->add($property->id(), self::OWN_KEY, DistributionKeyKind::Fixed);
        $other = self::givenOtherProperty()->units();
        self::assertNotSame([], $other);

        $this->expectException(UnitBelongsElsewhere::class);

        self::maintain()->hold($key, [$other[0]->id() => '1']);
    }

    /**
     * Zwei gleichzeitig gesetzte Anteile — der zweite bekommt eine Antwort.
     *
     * Beide sehen noch keinen Anteil zu dieser Einheit und legen beide einen
     * an; der eindeutige Index faengt den zweiten.
     */
    public function testSharesHeldInTheMeantimeAreRefusedReadably(): void
    {
        self::bootKernel();
        $property = self::givenProperty();
        $key = self::maintain()->add($property->id(), self::OWN_KEY, DistributionKeyKind::Fixed);
        $units = $property->units();
        self::assertNotSame([], $units);
        $unit = $units[0];

        $connection = self::manager()->getConnection();
        $connection->beginTransaction();
        $connection->executeStatement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');

        try {
            self::assertSame([], $key->shares());

            $this->heldByAnotherRequest($key->id(), $unit->id());

            self::maintain()->hold($key, [$unit->id() => '1']);
            self::fail('Der zweite Anteil kam durch.');
        } catch (RecordedInTheMeantime $problem) {
            self::assertNotSame('', $problem->getMessage(), 'Und zwar mit einer lesbaren Meldung');
        } finally {
            $connection->rollBack();
            $this->closeSecondConnection();
        }
    }

    /** Die vier berechneten Schluessel gelten ueberall. */
    public function testTheSystemKeysBelongToNoProperty(): void
    {
        self::bootKernel();

        foreach (self::keys()->all() as $key) {
            if ($key->isSystem()) {
                self::assertNull($key->propertyId(), $key->name().' gilt überall');
            }
        }
    }

    /** Und sie lassen sich nicht aendern. */
    public function testASystemKeyIsNotDeleted(): void
    {
        $client = self::editor();
        $key = self::systemKey();

        self::post($client, '/finanzen/verteilerschluessel/'.$key->id().'/loeschen', '/finanzen/verteilerschluessel?abschnitt=neu');

        self::assertNotNull(self::keys()->byId($key->id()), 'Der Systemschlüssel steht noch da');
    }

    protected static function testEmail(): string
    {
        return 'katalog@example.org';
    }

    /** Was im selben Augenblick eine andere Anfrage setzt. */
    private function heldByAnotherRequest(string $keyId, string $unitId): void
    {
        $this->secondConnection()->executeStatement(
            'INSERT INTO finance_distribution_key_share (id, key_id, unit_id, share)
             VALUES (gen_random_uuid(), ?, ?, ?)',
            [$keyId, $unitId, '2.0000'],
        );
    }

    private static function editor(): KernelBrowser
    {
        return self::signedInWith([FinancePermissions::VIEW, FinancePermissions::EDIT, FinancePermissions::DELETE]);
    }

    /**
     * @param array<string, string> $fields
     */
    private static function post(KernelBrowser $client, string $url, string $page, array $fields = []): void
    {
        $crawler = $client->request('GET', $page);
        $token = $crawler->filter('main input[name="_token"]');
        self::assertGreaterThan(0, $token->count(), 'Kein Formular auf '.$page);

        $client->request('POST', $url, [...$fields, '_token' => (string) $token->first()->attr('value')]);
    }

    private static function kindByName(string $name): ?CostKind
    {
        foreach (self::kinds()->all() as $kind) {
            if ($kind->name() === $name) {
                return $kind;
            }
        }

        return null;
    }

    private static function maintain(): MaintainDistributionKeys
    {
        $maintain = self::getContainer()->get(MaintainDistributionKeys::class);
        self::assertInstanceOf(MaintainDistributionKeys::class, $maintain);

        return $maintain;
    }

    private static function givenItemUsing(Property $property, DistributionKey $key): void
    {
        $items = self::getContainer()->get(CostItemRepository::class);
        self::assertInstanceOf(CostItemRepository::class, $items);
        $kinds = self::getContainer()->get(CostKindRepository::class);
        self::assertInstanceOf(CostKindRepository::class, $kinds);

        $all = $kinds->all();
        self::assertNotSame([], $all);

        $items->save(new CostItem($items->nextNumber(), $property->id(), $all[0], $key));
    }

    private static function keyByName(string $name): ?DistributionKey
    {
        foreach (self::keys()->all() as $key) {
            if ($key->name() === $name) {
                return $key;
            }
        }

        return null;
    }

    /** Ein Objekt, dem ein eigener Schluessel gehoeren kann. */
    private static function givenProperty(): Property
    {
        $id = self::manager()->getConnection()->fetchOne('SELECT id FROM property WHERE name = ?', [self::PROPERTY]);

        if (\is_string($id)) {
            $found = self::properties()->byId($id);
            self::assertInstanceOf(Property::class, $found);

            return $found;
        }

        $property = new Property(
            self::properties()->nextNumber(),
            self::PROPERTY,
            ManagementModes::of([ManagementMode::Rental]),
        );
        new Unit($property, 'WE 1');
        $property->managedAs($property->management()->activated());
        self::properties()->save($property);

        return $property;
    }

    /** Ein zweites Objekt — fuer die Frage, was nicht dazugehoert. */
    private static function givenOtherProperty(): Property
    {
        $id = self::manager()->getConnection()->fetchOne(
            'SELECT id FROM property WHERE name = ?',
            [self::OTHER_PROPERTY],
        );

        if (\is_string($id)) {
            $found = self::properties()->byId($id);
            self::assertInstanceOf(Property::class, $found);

            return $found;
        }

        $property = new Property(
            self::properties()->nextNumber(),
            self::OTHER_PROPERTY,
            ManagementModes::of([ManagementMode::Rental]),
        );
        new Unit($property, 'WE 1');
        $property->managedAs($property->management()->activated());
        self::properties()->save($property);

        return $property;
    }

    private static function manager(): EntityManagerInterface
    {
        $manager = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $manager);

        return $manager;
    }

    private static function properties(): PropertyRepository
    {
        $repository = self::getContainer()->get(PropertyRepository::class);
        self::assertInstanceOf(PropertyRepository::class, $repository);

        return $repository;
    }

    private static function systemKey(): DistributionKey
    {
        foreach (self::keys()->all() as $key) {
            if ($key->isSystem() && DistributionKeyKind::Area === $key->kind()) {
                return $key;
            }
        }

        self::fail('Der Flächenschlüssel fehlt.');
    }

    private static function kinds(): CostKindRepository
    {
        $repository = self::getContainer()->get(CostKindRepository::class);
        self::assertInstanceOf(CostKindRepository::class, $repository);

        return $repository;
    }

    private static function keys(): DistributionKeyRepository
    {
        $repository = self::getContainer()->get(DistributionKeyRepository::class);
        self::assertInstanceOf(DistributionKeyRepository::class, $repository);

        return $repository;
    }

    private static function cleanUp(): void
    {
        if (null === self::$kernel) {
            return;
        }

        $connection = self::manager()->getConnection();
        // Erst, was auf die anderen zeigt: die Fremdschlüssel halten sonst,
        // und ein gescheitertes Aufräumen vererbt seinen Rest an den
        // nächsten Test.
        $connection->executeStatement(
            'DELETE FROM finance_cost_item WHERE property_id IN (SELECT id FROM property WHERE name = ?)',
            [self::PROPERTY],
        );
        $connection->executeStatement('DELETE FROM finance_cost_kind WHERE name = ?', [self::OWN_KIND]);
        $connection->executeStatement('DELETE FROM finance_distribution_key WHERE name = ?', [self::OWN_KEY]);
        $connection->executeStatement('DELETE FROM property WHERE name IN (?, ?)', [self::PROPERTY, self::OTHER_PROPERTY]);
    }
}
