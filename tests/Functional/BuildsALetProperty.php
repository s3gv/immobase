<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Tests\Functional;

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
use App\Module\Party\Domain\TaxId;
use App\Module\Property\Domain\Address;
use App\Module\Property\Domain\BankAccount;
use App\Module\Property\Domain\ManagementMode;
use App\Module\Property\Domain\ManagementModes;
use App\Module\Property\Domain\Mea;
use App\Module\Property\Domain\Property;
use App\Module\Property\Domain\PropertyRepository;
use App\Module\Property\Domain\Unit;
use App\Module\Property\Domain\UnitOwner;
use App\Module\Property\Domain\UnitUsage;
use App\Module\Tenancy\Domain\EInvoiceTerms;
use App\Module\Tenancy\Domain\Payment;
use App\Module\Tenancy\Domain\PaymentDue;
use App\Module\Tenancy\Domain\PaymentMethod;
use App\Module\Tenancy\Domain\Rent;
use App\Module\Tenancy\Domain\RentStep;
use App\Module\Tenancy\Domain\Taxation;
use App\Module\Tenancy\Domain\Tenancy;
use App\Module\Tenancy\Domain\TenancyRepository;
use App\Module\Tenancy\Domain\Tenant;
use App\Module\Tenancy\Domain\Term;
use App\Shared\Bank\Bic;
use App\Shared\Bank\Iban;
use App\Shared\Contact\Email;
use App\Shared\Money\Money;
use App\Shared\Ui\Page;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Ein vermietetes Gewerbeobjekt — alles, was eine Dauermietrechnung braucht.
 *
 * Ein eigener Aufbau neben {@see BuildsABillableProperty}: dort geht es um
 * WEG-Abrechnung, hier um Vermietung. Die beiden brauchen verschiedene Dinge
 * — ein Ladenlokal mit Umsatzsteueroption, einen Eigentuemer **mit
 * Steuernummer**, ein Bankkonto am Objekt und einen Mieter mit Anschrift.
 *
 * Gewerbe, weil nur dort die Option nach § 9 UStG offensteht: bei Wohnraum
 * ist die Vermietung steuerfrei, und dann traegt die Rechnung keine Steuer.
 */
trait BuildsALetProperty
{
    use ConfiguresTheManagement;

    public const int LET_PROPERTY_NUMBER = 28001;

    private const string LET_PROPERTY_NAME = 'Mietobjekt Ladenprüfung';

    private static ?string $letPropertyId = null;

    private static ?string $letTenancyId = null;

    protected static function buildTheLetProperty(bool $withVat = true): void
    {
        self::theManagementIsReachable();

        $property = new Property(
            self::LET_PROPERTY_NUMBER,
            self::LET_PROPERTY_NAME,
            ManagementModes::of([ManagementMode::Rental]),
        );
        $property->moveTo(Address::of('Ladenweg 5', '40233', 'Düsseldorf'));
        $property->accountsAs($property->accounting()->collectedVia(BankAccount::of(
            Iban::fromString('DE02120300000000202051'),
            Bic::fromString('BYLADEM1XXX'),
            'Mietkonto Ladenweg',
            'Mietkonto',
        )));

        $unit = new Unit($property, 'Ladenlokal EG');
        $unit->describe('Ladenlokal EG', UnitUsage::Commercial);
        new UnitOwner($unit, self::aLandlord()->id(), Mea::of('1000.00', 1000));

        self::letProperties()->save($property);
        self::$letPropertyId = $property->id();

        self::aLetting($unit, $withVat);
    }

    protected static function letPropertyId(): string
    {
        self::assertIsString(self::$letPropertyId);

        return self::$letPropertyId;
    }

    protected static function letTenancyId(): string
    {
        self::assertIsString(self::$letTenancyId);

        return self::$letTenancyId;
    }

    /** Eine zweite Mietstufe — der Grund, aus dem eine Folgefassung entsteht. */
    protected static function alsoRaisesTheRent(string $from = '2027-07-01'): void
    {
        $tenancy = self::tenancies()->byId(self::letTenancyId());
        self::assertInstanceOf(Tenancy::class, $tenancy);

        new RentStep($tenancy, new DateTimeImmutable($from), Rent::of(
            Money::fromCents(195000),
            Money::fromCents(38000),
            Money::fromCents(15000),
            Money::zero(),
        ));
        self::tenancies()->save($tenancy);
    }

    /**
     * Die laufende Mietstufe wird nachtraeglich korrigiert.
     *
     * Der Fall, an dem sich das Einfrieren zeigt: eine neue Stufe spaeter im
     * Jahr liesse die Rechnung ohnehin in Ruhe, weil sie die Stufe ihres
     * eigenen Tages liest. Wer aber **diese** Stufe aendert, aendert die
     * Zahlen unter einem Schreiben, das laengst beim Mieter liegt.
     */
    protected static function alsoCorrectsTheRent(): void
    {
        $tenancy = self::tenancies()->byId(self::letTenancyId());
        self::assertInstanceOf(Tenancy::class, $tenancy);
        $step = $tenancy->schedule()->steps()[0] ?? null;
        self::assertInstanceOf(RentStep::class, $step);

        $step->charge(Rent::of(
            Money::fromCents(190000),
            Money::fromCents(35000),
            Money::fromCents(15000),
            Money::zero(),
        ));
        self::tenancies()->save($tenancy);
    }

    protected static function removeTheLetProperty(): void
    {
        if (null === self::$kernel) {
            return;
        }

        $manager = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $manager);
        $connection = $manager->getConnection();

        $connection->executeStatement(
            'DELETE FROM billing_rent_invoice WHERE property_id IN (SELECT id FROM property WHERE number = ?)',
            [self::LET_PROPERTY_NUMBER],
        );
        $connection->executeStatement(
            'DELETE FROM tenancy WHERE unit_id IN
                (SELECT u.id FROM property_unit u JOIN property p ON p.id = u.property_id WHERE p.number = ?)',
            [self::LET_PROPERTY_NUMBER],
        );
        $connection->executeStatement('DELETE FROM property WHERE number = ?', [self::LET_PROPERTY_NUMBER]);
        $connection->executeStatement('DELETE FROM party WHERE reference IN (98001, 98002)');
        self::forgetTheManagement();
        self::$letPropertyId = null;
        self::$letTenancyId = null;
    }

    /** Der Eigentuemer — mit Steuernummer, denn er stellt die Rechnung. */
    private static function aLandlord(): Party
    {
        foreach (self::letParties()->matching(PartyFilter::none(), Page::of(1, 500)) as $known) {
            if (98001 === $known->reference()) {
                return $known;
            }
        }

        $landlord = new Party(
            98001,
            PartyKind::Person,
            'Vermieter',
            'Viktor',
            PartyRoles::of([PartyRole::Owner]),
            Addresses::of([PostalAddress::of(AddressKind::Street, 'Eigentümerallee 1', '40233', 'Düsseldorf')]),
            ContactDetails::of([Email::fromString('viktor@example.org')]),
        );
        $landlord->taxedAs(TaxId::of('133/5711/0815'));
        self::letParties()->save($landlord);

        return $landlord;
    }

    private static function aTenant(): Party
    {
        foreach (self::letParties()->matching(PartyFilter::none(), Page::of(1, 500)) as $known) {
            if (98002 === $known->reference()) {
                return $known;
            }
        }

        $tenant = new Party(
            98002,
            PartyKind::Company,
            'Ladenbetrieb GmbH',
            null,
            PartyRoles::of([PartyRole::Tenant]),
            Addresses::of([PostalAddress::of(AddressKind::Street, 'Geschäftsweg 7', '40213', 'Düsseldorf')]),
            ContactDetails::of([Email::fromString('laden@example.org')]),
        );
        self::letParties()->save($tenant);

        return $tenant;
    }

    private static function aLetting(Unit $unit, bool $withVat): void
    {
        $tenancy = new Tenancy(self::tenancies()->nextNumber(), $unit->id());
        new Tenant($tenancy, self::aTenant()->id());
        $tenancy->runFor(Term::of(new DateTimeImmutable('2026-01-01'), null, null, null));
        new RentStep($tenancy, new DateTimeImmutable('2026-01-01'), Rent::of(
            Money::fromCents(180000),
            Money::fromCents(35000),
            Money::fromCents(15000),
            Money::zero(),
        ));
        $tenancy->taxAs($withVat ? Taxation::at(1900) : Taxation::exempt());
        // Was der Mieter fuer seine E-Rechnung angegeben hat — ohne das haelt
        // eine Rechnung mit Umsatzsteuer beim Ausstellen auf.
        $tenancy->paidBy(Payment::of(PaymentMethod::Transfer, PaymentDue::ThirdWorkingDay, EInvoiceTerms::of(
            'LADEN-4711',
            Email::fromString('laden-rechnung@example.org'),
            '',
            null,
        )));
        $tenancy->activate();
        self::tenancies()->save($tenancy);
        self::$letTenancyId = $tenancy->id();
    }

    private static function letProperties(): PropertyRepository
    {
        $properties = self::getContainer()->get(PropertyRepository::class);
        self::assertInstanceOf(PropertyRepository::class, $properties);

        return $properties;
    }

    private static function letParties(): PartyRepository
    {
        $parties = self::getContainer()->get(PartyRepository::class);
        self::assertInstanceOf(PartyRepository::class, $parties);

        return $parties;
    }

    private static function tenancies(): TenancyRepository
    {
        $tenancies = self::getContainer()->get(TenancyRepository::class);
        self::assertInstanceOf(TenancyRepository::class, $tenancies);

        return $tenancies;
    }
}
