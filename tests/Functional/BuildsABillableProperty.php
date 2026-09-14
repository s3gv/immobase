<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Module\Billing\Application\StatementPeriod;
use App\Module\Billing\Domain\FiscalPeriod;
use App\Module\Finance\Domain\AdvanceKind;
use App\Module\Finance\Domain\AdvancePayment;
use App\Module\Finance\Domain\AdvancePaymentRepository;
use App\Module\Finance\Domain\ChosenMeasure;
use App\Module\Finance\Domain\CostItem;
use App\Module\Finance\Domain\CostItemRepository;
use App\Module\Finance\Domain\CostItemYear;
use App\Module\Finance\Domain\CostKind;
use App\Module\Finance\Domain\CostKindRepository;
use App\Module\Finance\Domain\DistributionKey;
use App\Module\Finance\Domain\DistributionKeyKind;
use App\Module\Finance\Domain\DistributionKeyRepository;
use App\Module\Finance\Domain\EntryMode;
use App\Module\Finance\Domain\ReserveMovement;
use App\Module\Finance\Domain\ReserveMovementKind;
use App\Module\Finance\Domain\ReserveMovementRepository;
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
use App\Module\Property\Domain\Address;
use App\Module\Property\Domain\BankAccount;
use App\Module\Property\Domain\ManagementMode;
use App\Module\Property\Domain\ManagementModes;
use App\Module\Property\Domain\Mea;
use App\Module\Property\Domain\Measures;
use App\Module\Property\Domain\Property;
use App\Module\Property\Domain\PropertyRepository;
use App\Module\Property\Domain\Unit;
use App\Module\Property\Domain\UnitHousehold;
use App\Module\Property\Domain\UnitOwner;
use App\Module\Settings\Domain\Logo;
use App\Module\Settings\Domain\LogoRepository;
use App\Shared\Bank\Bic;
use App\Shared\Bank\Iban;
use App\Shared\Contact\Email;
use App\Shared\Money\Money;
use App\Shared\Ui\Page;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Ein Objekt, an dem sich abrechnen laesst.
 *
 * Zwei Einheiten mit Flaeche und Miteigentumsanteil, ein Eigentuemer, zwei
 * Kostenarten mit verschiedenen Verteilerschluesseln und die Zahlungen eines
 * Jahres. Klein genug zum Nachrechnen und gross genug, damit die
 * Verteilung etwas zu tun hat.
 */
trait BuildsABillableProperty
{
    public const int PROPERTY_NUMBER = 29001;
    private const string PROPERTY_NAME = 'Abrechnungsobjekt Prüfweg';

    private static ?string $propertyId = null;

    protected static function propertyId(): string
    {
        return self::$propertyId ?? '';
    }

    protected static function buildTheProperty(bool $withArea = true): void
    {
        $property = new Property(self::PROPERTY_NUMBER, self::PROPERTY_NAME, ManagementModes::of([ManagementMode::Weg]));
        $property->moveTo(Address::of('Prüfweg 3', '40213', 'Düsseldorf'));
        // Eine Gemeinschaft, die Hausgeld einsammelt, hat ein Konto. Ohne es
        // waere jedes Schreiben, das zum Zahlen auffordert, unvollstaendig —
        // und das Mahnwesen laesst sich ohne Konto gar nicht pruefen.
        $property->accountsAs($property->accounting()->collectedVia(BankAccount::of(
            Iban::fromString('DE02120300000000202051'),
            Bic::fromString('BYLADEM1XXX'),
            'WEG Prüfweg 3',
            'Hausgeldkonto',
        )));

        $first = new Unit($property, 'WE 1');
        $first->measure(Measures::of('78.40', null, null));
        $first->holdShare(Mea::of('250.00', 1000));

        $second = new Unit($property, 'WE 2');
        $second->measure(Measures::of($withArea ? '64.20' : null, null, null));
        $second->holdShare(Mea::of('200.00', 1000));

        $owner = self::anOwner();
        new UnitOwner($first, $owner->id(), Mea::of('250.00', 1000));
        new UnitOwner($second, $owner->id(), Mea::of('200.00', 1000));

        self::properties()->save($property);
        self::$propertyId = $property->id();

        self::theCosts($property, $first, $second);
        self::thePayments($first, $second);
        self::aLogo();
    }

    /**
     * Der Zeitraum eines Wirtschaftsjahres dieses Objekts.
     *
     * Ueber den Dienst und nicht von Hand: so steht im Test der Zeitraum, den
     * die Anwendung auch einfrieren wuerde.
     */
    protected static function aFiscalYear(int $year): FiscalPeriod
    {
        $period = self::getContainer()->get(StatementPeriod::class);
        self::assertInstanceOf(StatementPeriod::class, $period);

        return $period->of(self::propertyId(), $year);
    }

    /**
     * Die Kennungen der beiden Einheiten.
     *
     * @return list<string>
     */
    protected static function unitIds(): array
    {
        $properties = self::properties();
        $property = $properties->byId(self::propertyId());

        return null === $property
            ? []
            : array_map(static fn (Unit $unit): string => $unit->id(), $property->units());
    }

    protected static function raiseTheCosts(): void
    {
        foreach (self::items()->forProperty(self::propertyId()) as $item) {
            foreach ($item->years()->all() as $year) {
                $year->cost($year->amount()->plus(Money::fromCents(10000)), $year->mode());
            }

            self::items()->save($item);
        }
    }

    protected static function removeTheProperty(): void
    {
        if (null === self::$kernel) {
            return;
        }

        $manager = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $manager);
        $connection = $manager->getConnection();
        // Die Reihenfolge ist die der Fremdschluessel: was haelt, geht zuerst.
        // Die Mahnungen halten nichts fest, aber ihre Forderungen zeigen auf
        // Objekt und Einheit: was stehen bliebe, faende der naechste Test vor
        // und mahnte es noch einmal.
        $connection->executeStatement('DELETE FROM dunning_notice');
        $connection->executeStatement('DELETE FROM dunning_claim');
        $connection->executeStatement('DELETE FROM billing_statement');
        $connection->executeStatement('DELETE FROM billing_plan');
        $connection->executeStatement('DELETE FROM billing_asset_report');
        $connection->executeStatement('DELETE FROM billing_budget');
        // Was eine Freigabe in die Staffel geschrieben hat, haelt die Einheit.
        $connection->executeStatement('DELETE FROM finance_house_money');
        $connection->executeStatement('DELETE FROM finance_reserve_movement');
        $connection->executeStatement('DELETE FROM finance_cost_item WHERE number BETWEEN 90001 AND 90020');
        // Ein freigegebener Budgetplan mit Darlehen legt eines an, und es
        // haelt das Objekt fest.
        $connection->executeStatement('DELETE FROM finance_loan');
        $connection->executeStatement('DELETE FROM property WHERE number = ?', [self::PROPERTY_NUMBER]);
        $connection->executeStatement('DELETE FROM party WHERE reference = ?', [99001]);
        // Das Logo gehoert der Installation und nicht diesem Objekt: bliebe
        // es stehen, faende der naechste Test es vor und rechnete damit.
        $connection->executeStatement('DELETE FROM settings_logo');
        self::$propertyId = null;
    }

    /**
     * Eine dritte Kostenart, verteilt nach Personen.
     *
     * Steht nicht im Grundaufbau: sie verlangt fuer jede Einheit eine
     * Personenzahl, und die meisten Tests kommen ohne aus. Wer sie
     * hinzunimmt, nimmt auch die Pflicht mit, sie zu fuellen.
     */
    protected static function alsoCostsByPerson(Money $amount): void
    {
        $property = self::properties()->byId(self::propertyId());
        self::assertInstanceOf(Property::class, $property);

        $item = new CostItem(90003, $property->id(), self::aCostKind('Prüfmüll', 93), self::aKeyOf(DistributionKeyKind::Persons));
        $item->splitBy(true);
        new CostItemYear($item, 2026, $amount, EntryMode::Total);
        self::items()->save($item);
    }

    /**
     * Eine Kostenart nach Miteigentumsanteilen.
     *
     * Der haeufigste Schluessel einer WEG — und lange der einzige, den kein
     * Test benutzt hat. Genau darin steckte ein Fehler: die Einheitenkurzform
     * trug den Anteil als „250/1000", und daraus laesst sich nicht verteilen.
     */
    protected static function alsoCostsByMea(Money $amount, int $year = 2026): void
    {
        $property = self::properties()->byId(self::propertyId());
        self::assertInstanceOf(Property::class, $property);

        $item = new CostItem(90004, $property->id(), self::aCostKind('Prüfverwaltung', 94), self::aKeyOf(DistributionKeyKind::Mea));
        $item->splitBy(true);
        new CostItemYear($item, $year, $amount, EntryMode::Total);
        self::items()->save($item);
    }

    /**
     * Eine Kostenposition, die eine beschlossene Massnahme bezahlt.
     *
     * Die Nummer des Beschlusses steht an beiden Enden: an der Sonderumlage,
     * die hereinkam, und hier an der Rechnung, die hinausging.
     */
    protected static function alsoCostsForTheMeasure(string $reference, Money $amount, int $year = 2026): void
    {
        $property = self::properties()->byId(self::propertyId());
        self::assertInstanceOf(Property::class, $property);

        // Je Jahr eine eigene Position: eine Massnahme wird ueber mehrere
        // Jahre bezahlt, und die Nummern muessen sich unterscheiden.
        $number = 90005 + $year - 2026;
        $item = new CostItem($number, $property->id(), self::aCostKind('Prüfmaßnahme', 95), self::aKeyOf(DistributionKeyKind::Mea));
        $item->paysFor(ChosenMeasure::of($reference, 'Prüfmaßnahme'));
        new CostItemYear($item, $year, $amount, EntryMode::Total);
        self::items()->save($item);
    }

    /**
     * Eine Zufuehrung zur Erhaltungsruecklage im Vorjahr.
     *
     * Je Einheit eine Buchung: eine Zufuehrung ohne Einheit waere nicht
     * nachvollziehbar, und genau das verlangt {@see ReserveMovementKind}.
     */
    protected static function alsoPaidIntoTheReserve(Money $each, int $year = 2026): void
    {
        $property = self::properties()->byId(self::propertyId());
        self::assertInstanceOf(Property::class, $property);

        foreach ($property->units() as $unit) {
            self::reserves()->save(new ReserveMovement(
                $property->id(),
                ReserveMovementKind::Contribution,
                new DateTimeImmutable(\sprintf('%d-12-31', $year)),
                $each,
                $unit->id(),
            ));
        }
    }

    /**
     * Eine Bewegung auf der Ruecklage an einem bestimmten Tag.
     *
     * Fuer den Vermoegensbericht: er fragt nach einem Stichtag, und der Tag
     * einer Buchung entscheidet, ob sie noch hineingehoert.
     */
    protected static function alsoMovedTheReserve(ReserveMovementKind $kind, Money $amount, string $day): void
    {
        $property = self::properties()->byId(self::propertyId());
        self::assertInstanceOf(Property::class, $property);
        $unit = $kind->needsAUnit() ? ($property->units()[0] ?? null) : null;

        self::reserves()->save(new ReserveMovement(
            $property->id(),
            $kind,
            new DateTimeImmutable($day),
            $amount,
            $unit?->id(),
        ));
    }

    /**
     * Eine faellige Vorauszahlung, die nicht oder nur halb ankam.
     *
     * Der Normalfall ist bezahlt — {@see AdvancePayment} legt sie so an. Wer
     * einen Rueckstand braucht, schaltet um, und genau das tut hier jemand.
     */
    protected static function alsoMissedAnAdvance(
        int $unitAt,
        Money $expected,
        int $year,
        ?Money $part = null,
        AdvanceKind $kind = AdvanceKind::HouseMoney,
    ): void {
        $unitId = self::unitIds()[$unitAt] ?? null;
        self::assertIsString($unitId);

        $payment = new AdvancePayment(
            $unitId,
            $kind,
            $year,
            new DateTimeImmutable(\sprintf('%d-12-01', $year)),
            $expected,
        );
        $payment->missed($part);
        self::paid()->saveAll([$payment]);
    }

    /**
     * Eine beschlossene Sonderumlage, faellig an einem Tag des Jahres.
     *
     * Bezahlt, wie jede Zahlung erst einmal gilt — hier geht es darum, wohin
     * sie gehoert, und nicht darum, ob sie ankam.
     */
    protected static function alsoLevied(
        int $unitAt,
        Money $amount,
        string $day,
        AdvanceKind $kind = AdvanceKind::SpecialLevy,
        string $reference = '',
    ): void {
        $unitId = self::unitIds()[$unitAt] ?? null;
        self::assertIsString($unitId);

        $due = new DateTimeImmutable($day);
        self::paid()->saveAll([new AdvancePayment(
            $unitId,
            $kind,
            (int) $due->format('Y'),
            $due,
            $amount,
            $reference,
        )]);
    }

    /** Die Personenzahl fuer die Tage ohne Mietvertrag. */
    protected static function housesItself(int $unitAt, int $people, string $since = '2026-01-01'): void
    {
        $property = self::properties()->byId(self::propertyId());
        self::assertInstanceOf(Property::class, $property);

        $unit = $property->units()[$unitAt] ?? null;
        self::assertInstanceOf(Unit::class, $unit);

        new UnitHousehold($unit, new DateTimeImmutable($since), $people);
        self::properties()->save($property);
    }

    /**
     * Ein Logo in den Einstellungen.
     *
     * Ohne eines liefe der Briefkopf am Bild vorbei — und genau dort steckte
     * ein Fehler, den erst das Durchklicken fand: FPDF kann den Typ einer
     * `data:`-Adresse nicht raten.
     */
    private static function aLogo(): void
    {
        $logos = self::getContainer()->get(LogoRepository::class);
        self::assertInstanceOf(LogoRepository::class, $logos);

        if (null !== $logos->current()) {
            return;
        }

        $image = imagecreatetruecolor(Logo::WIDTH, Logo::HEIGHT);
        self::assertNotFalse($image);
        ob_start();
        imagepng($image);
        $bytes = (string) ob_get_clean();

        $logos->save(new Logo($bytes, new DateTimeImmutable('2026-01-01')));
    }

    private static function anOwner(): Party
    {
        // Gesucht, bevor angelegt: ein Testlauf, der vorzeitig abbricht,
        // laesst seinen Kontakt stehen, und der naechste liefe sonst in den
        // eindeutigen Index.
        foreach (self::parties()->matching(PartyFilter::none(), Page::of(1, 500)) as $known) {
            if (99001 === $known->reference()) {
                return $known;
            }
        }

        $owner = new Party(
            99001,
            PartyKind::Person,
            'Prüfer',
            'Paula',
            PartyRoles::of([PartyRole::Owner]),
            Addresses::of([PostalAddress::of(AddressKind::Street, 'Kontrollweg 9', '40233', 'Düsseldorf')]),
            ContactDetails::of([Email::fromString('paula@example.org')]),
        );
        self::parties()->save($owner);

        return $owner;
    }

    private static function theCosts(Property $property, Unit $first, Unit $second): void
    {
        // Die Systemschluessel liefert die Installation mit — gesucht wird
        // nach ihrer Sorte, nicht nach ihrem Namen: der ist uebersetzbar.
        $byArea = self::aKeyOf(DistributionKeyKind::Area);
        $byUnits = self::aKeyOf(DistributionKeyKind::Units);
        $tax = self::aCostKind('Prüfsteuer', 91);
        $service = self::aCostKind('Prüfdienst', 92);

        $one = new CostItem(90001, $property->id(), $tax, $byArea);
        $one->splitBy(true);
        new CostItemYear($one, 2026, Money::fromCents(124050), EntryMode::Total);
        self::items()->save($one);

        $two = new CostItem(90002, $property->id(), $service, $byUnits);
        $two->splitBy(true);
        new CostItemYear($two, 2026, Money::fromCents(48000), EntryMode::Total);
        self::items()->save($two);
    }

    private static function thePayments(Unit $first, Unit $second): void
    {
        $payments = [];

        foreach ([$first, $second] as $unit) {
            for ($month = 1; $month <= 12; ++$month) {
                $payments[] = new AdvancePayment(
                    $unit->id(),
                    AdvanceKind::HouseMoney,
                    2026,
                    new DateTimeImmutable(\sprintf('2026-%02d-01', $month)),
                    Money::fromCents(30000),
                );
            }
        }

        self::paid()->saveAll($payments);
    }

    private static function aKeyOf(DistributionKeyKind $kind): DistributionKey
    {
        foreach (self::keys()->forProperty(null) as $key) {
            if ($key->kind() === $kind) {
                return $key;
            }
        }

        $fresh = new DistributionKey(null, 'Prüfschlüssel '.$kind->value, $kind, true);
        self::keys()->save($fresh);

        return $fresh;
    }

    private static function aCostKind(string $name, int $ordering): CostKind
    {
        foreach (self::kinds()->all() as $known) {
            if ($known->name() === $name) {
                return $known;
            }
        }

        $fresh = new CostKind($name, true, $ordering);
        self::kinds()->save($fresh);

        return $fresh;
    }

    private static function reserves(): ReserveMovementRepository
    {
        $found = self::getContainer()->get(ReserveMovementRepository::class);
        self::assertInstanceOf(ReserveMovementRepository::class, $found);

        return $found;
    }

    private static function properties(): PropertyRepository
    {
        $found = self::getContainer()->get(PropertyRepository::class);
        self::assertInstanceOf(PropertyRepository::class, $found);

        return $found;
    }

    private static function parties(): PartyRepository
    {
        $found = self::getContainer()->get(PartyRepository::class);
        self::assertInstanceOf(PartyRepository::class, $found);

        return $found;
    }

    private static function keys(): DistributionKeyRepository
    {
        $found = self::getContainer()->get(DistributionKeyRepository::class);
        self::assertInstanceOf(DistributionKeyRepository::class, $found);

        return $found;
    }

    private static function kinds(): CostKindRepository
    {
        $found = self::getContainer()->get(CostKindRepository::class);
        self::assertInstanceOf(CostKindRepository::class, $found);

        return $found;
    }

    private static function items(): CostItemRepository
    {
        $found = self::getContainer()->get(CostItemRepository::class);
        self::assertInstanceOf(CostItemRepository::class, $found);

        return $found;
    }

    private static function paid(): AdvancePaymentRepository
    {
        $found = self::getContainer()->get(AdvancePaymentRepository::class);
        self::assertInstanceOf(AdvancePaymentRepository::class, $found);

        return $found;
    }
}
