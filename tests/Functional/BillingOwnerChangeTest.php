<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Module\Billing\Application\ComposeStatement;
use App\Module\Billing\Domain\ProposedDocument;
use App\Module\Billing\Domain\ProposedLine;
use App\Module\Billing\Domain\Statement;
use App\Module\Billing\Domain\StatementKind;
use App\Module\Billing\Domain\StatementRepository;
use App\Module\Party\Domain\Addresses;
use App\Module\Party\Domain\AddressKind;
use App\Module\Party\Domain\ContactDetails;
use App\Module\Party\Domain\Party;
use App\Module\Party\Domain\PartyFilter;
use App\Module\Party\Domain\PartyKind;
use App\Module\Party\Domain\PartyRepository;
use App\Module\Party\Domain\PartyRole;
use App\Module\Party\Domain\PartyRoles;
use App\Module\Party\Domain\PostalAddress;
use App\Module\Property\Application\AssignOwners;
use App\Module\Property\Domain\Holding;
use App\Module\Property\Domain\Mea;
use App\Module\Property\Domain\Property;
use App\Module\Property\Domain\PropertyRepository;
use App\Module\Property\Domain\Unit;
use App\Module\Property\Domain\UnitOwner;
use App\Shared\Contact\Email;
use App\Shared\Money\Money;
use App\Shared\Ui\Page;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Der unterjaehrige Eigentuemerwechsel.
 *
 * Ohne Zeitachse am Eigentum war ein Verkauf zum 1. Juli nicht falsch
 * abgerechnet, sondern **unsichtbar**: der Kaeufer bekam die
 * Hausgeldabrechnung fuer das ganze Jahr, einschliesslich des halben, das ihm
 * nicht gehoerte, und die Anwendung konnte nicht einmal warnen.
 *
 * Die Zusicherung ist dieselbe wie beim Mieterwechsel: **was zwei nacheinander
 * tragen, ist zusammen genau das, was einer allein getragen haette.** Der Lauf
 * vor dem Verkauf ist der Massstab.
 */
final class BillingOwnerChangeTest extends WebTestCase
{
    use BuildsABillableProperty;

    private const int BUYER = 99005;

    protected function tearDown(): void
    {
        // Erst das Objekt: seine Eigentumszeilen halten die Kaeuferin.
        self::removeTheProperty();
        self::removeTheBuyer();

        parent::tearDown();
    }

    /** Zwei Eigentuemer nacheinander, zusammen genau ein Jahr. */
    public function testWhatTwoOwnersBearTogetherIsWhatOneWouldHaveBorne(): void
    {
        $before = self::theOwnersLetter(self::documents());

        self::sellTheFirstUnitOn('2026-07-01');

        $after = self::ownerLettersOfTheFirstUnit(self::documents());

        self::assertCount(2, $after, 'Zwei Eigentümerabschnitte, zwei Hausgeldabrechnungen');

        foreach (self::costKindsIn($before) as $costKind) {
            self::assertTrue(
                self::amountOf(self::letterAt($after, 0), $costKind)
                    ->plus(self::amountOf(self::letterAt($after, 1), $costKind))
                    ->equals(self::amountOf($before, $costKind)),
                $costKind.': zusammen genau das, was einer allein getragen hätte',
            );
        }
    }

    /** Und jeder traegt seine Tage — auch bei einem Wechsel mitten im Monat. */
    public function testTheChangeIsSplitToTheDay(): void
    {
        self::documents();
        self::sellTheFirstUnitOn('2026-07-15');

        $after = self::ownerLettersOfTheFirstUnit(self::documents());

        self::assertSame('2026-01-01', self::letterAt($after, 0)->from->format('Y-m-d'));
        self::assertSame('2026-07-14', self::letterAt($after, 0)->to->format('Y-m-d'), 'Der Verkäufer trägt bis zum Vortag');
        self::assertSame('2026-07-15', self::letterAt($after, 1)->from->format('Y-m-d'));
        self::assertSame('2026-12-31', self::letterAt($after, 1)->to->format('Y-m-d'));

        self::assertSame('Paula Prüfer', self::letterAt($after, 0)->recipientLabel);
        self::assertSame('Neue Eigentümerin', self::letterAt($after, 1)->recipientLabel);

        foreach ([[self::letterAt($after, 0), 195], [self::letterAt($after, 1), 170]] as [$document, $days]) {
            foreach ($document->lines as $line) {
                self::assertSame($days, $line->distribution->daysOf(), $line->costKind);
                self::assertSame(365, $line->distribution->daysTotal(), $line->costKind);
            }
        }
    }

    /** Die zweite Einheit merkt von alldem nichts. */
    public function testTheOtherUnitKeepsItsSingleStatement(): void
    {
        $before = self::secondUnitLetter(self::documents());

        self::sellTheFirstUnitOn('2026-07-01');

        $after = self::secondUnitLetter(self::documents());

        self::assertSame('2026-01-01', $after->from->format('Y-m-d'));
        self::assertSame('2026-12-31', $after->to->format('Y-m-d'));
        self::assertTrue($after->balance()->equals($before->balance()));
    }

    /**
     * Verkauft und zurueckgekauft — dieselbe Partei, zwei Zeitraeume.
     *
     * Die Datenbank laesst das zu, solange sich die Zeitraeume nicht
     * ueberschneiden. Zugeordnet werden die Zeilen deshalb ueber ihre eigene
     * Kennung und nicht ueber den Kontakt: sonst waeren beide Zeitraeume
     * derselbe Eintrag, und der zweite ueberschriebe den ersten.
     */
    public function testASellerWhoBuysBackLaterGetsTwoStatements(): void
    {
        self::documents();
        self::sellTheFirstUnitOn('2026-04-01');
        self::sellItBackOn('2026-10-01');

        $letters = self::ownerLettersOfTheFirstUnit(self::documents());

        self::assertCount(3, $letters, 'Verkauf und Rückkauf sind drei Abschnitte');
        self::assertSame(
            ['Paula Prüfer', 'Neue Eigentümerin', 'Paula Prüfer'],
            array_map(static fn (ProposedDocument $d): string => $d->recipientLabel, $letters),
        );
        self::assertSame(
            ['2026-01-01', '2026-04-01', '2026-10-01'],
            array_map(static fn (ProposedDocument $d): string => $d->from->format('Y-m-d'), $letters),
        );
    }

    /** Und auch dann ist die Summe genau das, was einer allein getragen hätte. */
    public function testTheThreeSectionsStillAddUpToTheWholeYear(): void
    {
        $before = self::theOwnersLetter(self::documents());

        self::sellTheFirstUnitOn('2026-04-01');
        self::sellItBackOn('2026-10-01');

        $letters = self::ownerLettersOfTheFirstUnit(self::documents());

        foreach (self::costKindsIn($before) as $costKind) {
            $borne = Money::zero();

            foreach ($letters as $letter) {
                $borne = $borne->plus(self::amountOf($letter, $costKind));
            }

            self::assertTrue($borne->equals(self::amountOf($before, $costKind)), $costKind);
        }
    }

    /**
     * Die Verkaeuferin kauft zurueck — eine zweite Zeile fuer dieselbe Partei.
     *
     * Ueber den Anwendungsdienst und nicht am Modell vorbei: genau dort muss
     * sich zeigen, dass die Zuordnung ueber die Zeilenkennung laeuft.
     */
    private static function sellItBackOn(string $day): void
    {
        $properties = self::getContainer()->get(PropertyRepository::class);
        self::assertInstanceOf(PropertyRepository::class, $properties);
        $property = $properties->byId(self::propertyId());
        self::assertInstanceOf(Property::class, $property);

        $unit = self::firstUnitOf($property);
        $back = new DateTimeImmutable($day);
        $rows = [];

        foreach ($unit->owners() as $owner) {
            $rows[$owner->id()] = [
                'party' => $owner->partyId(),
                'mea' => $owner->mea()->numerator(),
                'von' => $owner->holding()->from()?->format('Y-m-d') ?? '',
                // Die Kaeuferin gibt wieder ab: ihr Zeitraum bekommt ein Ende.
                'bis' => null === $owner->holding()->to()
                    ? $back->modify('-1 day')->format('Y-m-d')
                    : $owner->holding()->to()->format('Y-m-d'),
            ];
        }

        $rows['zurueck'] = [
            'party' => self::theSeller($unit)->partyId(),
            'mea' => '250',
            'von' => $day,
            'bis' => '',
        ];

        $assign = self::getContainer()->get(AssignOwners::class);
        self::assertInstanceOf(AssignOwners::class, $assign);
        $assign->to($unit, $unit->mea(), $rows);
    }

    /** Wer zuerst da war — die Partei mit dem frühesten Ende. */
    private static function theSeller(Unit $unit): UnitOwner
    {
        $owners = $unit->owners();
        usort(
            $owners,
            static fn (UnitOwner $one, UnitOwner $other): int => ($one->holding()->to() ?? new DateTimeImmutable('2999-12-31'))
                <=> ($other->holding()->to() ?? new DateTimeImmutable('2999-12-31')),
        );

        $first = $owners[0] ?? null;
        self::assertInstanceOf(UnitOwner::class, $first);

        return $first;
    }

    /**
     * Ein Lauf ueber das Objekt — die Schreiben, die dabei herauskaemen.
     *
     * @return list<ProposedDocument>
     */
    private static function documents(): array
    {
        // Einmal bauen und zweimal rechnen: der Test vergleicht den Lauf vor
        // dem Verkauf mit dem danach, und das geht nur an demselben Objekt.
        if ('' === self::propertyId()) {
            self::buildTheProperty();
        }

        $statements = self::getContainer()->get(StatementRepository::class);
        self::assertInstanceOf(StatementRepository::class, $statements);
        $statement = new Statement(
            $statements->nextNumber(),
            self::propertyId(),
            self::PROPERTY_NUMBER,
            self::aFiscalYear(2026),
        );
        $statements->save($statement);

        $compose = self::getContainer()->get(ComposeStatement::class);
        self::assertInstanceOf(ComposeStatement::class, $compose);
        $offered = $compose->offered($statement);

        return $compose->of(
            $statement,
            array_map(static fn (object $cost): string => $cost->costYearId, $offered['costs']),
            array_map(static fn (object $payment): string => $payment->paymentId, $offered['payments']),
        )->documents;
    }

    /**
     * Die erste Einheit wechselt den Eigentuemer.
     *
     * Zwei Angaben, nicht eine: beim bisherigen Eigentuemer ein Ende, beim
     * neuen ein Anfang am Tag danach. Am selben Tag gehoerte die Wohnung
     * sonst zweien.
     */
    private static function sellTheFirstUnitOn(string $day): void
    {
        $properties = self::getContainer()->get(PropertyRepository::class);
        self::assertInstanceOf(PropertyRepository::class, $properties);
        $property = $properties->byId(self::propertyId());
        self::assertInstanceOf(Property::class, $property);

        $unit = self::firstUnitOf($property);
        $sold = new DateTimeImmutable($day);

        foreach ($unit->owners() as $owner) {
            $owner->holdsFrom(Holding::of(null, $sold->modify('-1 day')));
        }

        new UnitOwner($unit, self::theBuyer()->id(), Mea::of('250.00', 1000), Holding::of($sold, null));

        $properties->save($property);
    }

    private static function firstUnitOf(Property $property): Unit
    {
        $unit = $property->units()[0] ?? null;
        self::assertInstanceOf(Unit::class, $unit);

        return $unit;
    }

    /**
     * @param list<ProposedDocument> $documents
     */
    private static function theOwnersLetter(array $documents): ProposedDocument
    {
        $letters = self::ownerLettersOfTheFirstUnit($documents);

        self::assertCount(1, $letters, 'Vor dem Verkauf ist es eines');

        return self::letterAt($letters, 0);
    }

    /**
     * @param list<ProposedDocument> $documents
     *
     * @return list<ProposedDocument>
     */
    private static function ownerLettersOfTheFirstUnit(array $documents): array
    {
        $found = array_values(array_filter(
            $documents,
            static fn (ProposedDocument $d): bool => StatementKind::HouseMoney === $d->kind && 1 === $d->unitNumber,
        ));
        usort($found, static fn (ProposedDocument $one, ProposedDocument $other): int => $one->from <=> $other->from);

        return $found;
    }

    /**
     * @param list<ProposedDocument> $documents
     */
    private static function secondUnitLetter(array $documents): ProposedDocument
    {
        foreach ($documents as $document) {
            if (2 === $document->unitNumber) {
                return $document;
            }
        }

        self::fail('Die zweite Einheit bekommt kein Schreiben.');
    }

    /**
     * Das Schreiben an dieser Stelle — und die Absage, wenn es fehlt.
     *
     * @param list<ProposedDocument> $documents
     */
    private static function letterAt(array $documents, int $at): ProposedDocument
    {
        $document = $documents[$at] ?? null;
        self::assertInstanceOf(ProposedDocument::class, $document, 'Das '.($at + 1).'. Schreiben fehlt.');

        return $document;
    }

    /** @return list<string> */
    private static function costKindsIn(ProposedDocument $document): array
    {
        return array_map(static fn (ProposedLine $line): string => $line->costKind, $document->lines);
    }

    private static function amountOf(ProposedDocument $document, string $costKind): Money
    {
        foreach ($document->lines as $line) {
            if ($line->costKind === $costKind) {
                return $line->amount;
            }
        }

        self::fail($costKind.' fehlt auf dem Schreiben an '.$document->recipientLabel.'.');
    }

    private static function theBuyer(): Party
    {
        $parties = self::getContainer()->get(PartyRepository::class);
        self::assertInstanceOf(PartyRepository::class, $parties);

        foreach ($parties->matching(PartyFilter::none(), Page::of(1, 500)) as $known) {
            if (self::BUYER === $known->reference()) {
                return $known;
            }
        }

        $buyer = new Party(
            self::BUYER,
            PartyKind::Person,
            'Eigentümerin',
            'Neue',
            PartyRoles::of([PartyRole::Owner]),
            Addresses::of([PostalAddress::of(AddressKind::Street, 'Kaufweg 2', '40233', 'Düsseldorf')]),
            ContactDetails::of([Email::fromString('kaeuferin@example.org')]),
        );
        $parties->save($buyer);

        return $buyer;
    }

    private static function removeTheBuyer(): void
    {
        if (null === self::$kernel) {
            return;
        }

        $manager = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $manager);
        $manager->getConnection()->executeStatement(
            'DELETE FROM party WHERE reference = ?',
            [self::BUYER],
        );
    }
}
