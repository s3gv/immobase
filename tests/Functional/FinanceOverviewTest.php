<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Module\Finance\Application\SurveyCosts;
use App\Module\Finance\Domain\CostItem;
use App\Module\Finance\Domain\CostItemRepository;
use App\Module\Finance\Domain\CostItemYear;
use App\Module\Finance\Domain\CostKind;
use App\Module\Finance\Domain\CostKindRepository;
use App\Module\Finance\Domain\DistributionKey;
use App\Module\Finance\Domain\DistributionKeyRepository;
use App\Module\Finance\Domain\EntryMode;
use App\Module\Finance\Domain\FinancePermissions;
use App\Module\Property\Domain\ManagementMode;
use App\Module\Property\Domain\ManagementModes;
use App\Module\Property\Domain\Property;
use App\Module\Property\Domain\PropertyRepository;
use App\Module\Property\Domain\Unit;
use App\Shared\Money\Money;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Die Uebersicht der Finanzen: wo die Kosten liegen.
 *
 * Gerechnet wird ueber alle Objekte hinweg und je Wirtschaftsjahr. Was
 * gezaehlt wird, ist der Betrag des Jahres — bei „fertig verteilt" also die
 * Summe der Einzelbetraege und nicht die Rechnungssumme.
 */
final class FinanceOverviewTest extends WebTestCase
{
    use SignsIn;

    private const string PROPERTY = 'Übersichtsobjekt Prüfung';

    protected function tearDown(): void
    {
        self::cleanUp();
        self::removeTestUser();

        parent::tearDown();
    }

    /** Die Summe des Jahres und, was davon der Mieter traegt. */
    public function testTheYearIsSummedAndSplitByWhoBearsIt(): void
    {
        self::bootKernel();
        self::givenItem(2026, 120000, true);
        self::givenItem(2026, 30000, false);
        self::givenItem(2025, 999900, true);

        $breakdown = self::survey()->overview(2026, 2026)['breakdown'];

        self::assertSame(150000, $breakdown->total->cents(), 'Nur das gewählte Jahr');
        self::assertSame(120000, $breakdown->apportionable->cents());
        self::assertSame(30000, $breakdown->ownCosts()->cents());
        self::assertSame(80, $breakdown->apportionableShare());
    }

    /**
     * Ein Jahr, zu dem es nichts gibt, wird zurechtgerueckt.
     *
     * Es kommt aus der Adresszeile und ist damit Eingabe — „?jahr=1999" ist
     * kein Fehler, sondern eine Angabe, die es so nicht gibt.
     */
    public function testAnUnknownYearFallsBackToTheNewest(): void
    {
        self::bootKernel();
        self::givenItem(2026, 120000, true);
        self::givenItem(2025, 50000, true);

        $overview = self::survey()->overview(2026, 1999);

        self::assertSame(2026, $overview['breakdown']->fiscalYear);
        self::assertSame([2026, 2025], $overview['years'], 'Das jüngste zuerst');
    }

    /** Ohne einen einzigen Wert steht trotzdem ein Jahr zur Wahl. */
    public function testWithoutAnyValueTheCurrentYearStands(): void
    {
        self::bootKernel();

        $overview = self::survey()->overview(2026, null);

        self::assertSame([2026], $overview['years']);
        self::assertTrue($overview['breakdown']->isEmpty());
    }

    /** Die Seite zeichnet sich — samt Diagrammen. */
    public function testThePageRenders(): void
    {
        $client = self::signedInWith([FinancePermissions::VIEW]);
        self::givenItem(2026, 120000, true);

        $client->request('GET', '/finanzen?jahr=2026');
        $body = (string) $client->getResponse()->getContent();

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('1.200,00', $body, 'Die Summe steht da');
        self::assertSelectorExists('.ib-bars__fill', 'Und die Verteilung als Balken');
        self::assertSelectorExists('.ib-ring__share', 'Und der Anteil als Ring');
    }

    protected static function testEmail(): string
    {
        return 'uebersicht@example.org';
    }

    private static function survey(): SurveyCosts
    {
        $survey = self::getContainer()->get(SurveyCosts::class);
        self::assertInstanceOf(SurveyCosts::class, $survey);

        return $survey;
    }

    private static function givenItem(int $fiscalYear, int $cents, bool $apportionable): void
    {
        $item = new CostItem(
            self::items()->nextNumber(),
            self::givenProperty()->id(),
            self::kind(),
            self::key(),
        );
        $item->apportionAs($apportionable);
        new CostItemYear($item, $fiscalYear, Money::fromCents($cents), EntryMode::Total);
        self::items()->save($item);
    }

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

    private static function kind(): CostKind
    {
        $kinds = self::getContainer()->get(CostKindRepository::class);
        self::assertInstanceOf(CostKindRepository::class, $kinds);
        $all = $kinds->all();
        self::assertNotSame([], $all);

        return $all[0];
    }

    private static function key(): DistributionKey
    {
        $keys = self::getContainer()->get(DistributionKeyRepository::class);
        self::assertInstanceOf(DistributionKeyRepository::class, $keys);

        foreach ($keys->all() as $key) {
            if ($key->isSystem() && !$key->kind()->isMetered()) {
                return $key;
            }
        }

        self::fail('Es gibt keinen berechneten Systemschlüssel.');
    }

    private static function items(): CostItemRepository
    {
        $repository = self::getContainer()->get(CostItemRepository::class);
        self::assertInstanceOf(CostItemRepository::class, $repository);

        return $repository;
    }

    private static function properties(): PropertyRepository
    {
        $repository = self::getContainer()->get(PropertyRepository::class);
        self::assertInstanceOf(PropertyRepository::class, $repository);

        return $repository;
    }

    private static function manager(): EntityManagerInterface
    {
        $manager = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $manager);

        return $manager;
    }

    private static function cleanUp(): void
    {
        if (null === self::$kernel) {
            return;
        }

        $connection = self::manager()->getConnection();
        $connection->executeStatement(
            'DELETE FROM finance_cost_item WHERE property_id IN (SELECT id FROM property WHERE name = ?)',
            [self::PROPERTY],
        );
        $connection->executeStatement('DELETE FROM property WHERE name = ?', [self::PROPERTY]);
    }
}
