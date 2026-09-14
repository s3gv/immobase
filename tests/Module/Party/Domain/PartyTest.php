<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Tests\Module\Party\Domain;

use App\Module\Party\Domain\Addresses;
use App\Module\Party\Domain\AddressKind;
use App\Module\Party\Domain\ContactDetails;
use App\Module\Party\Domain\Party;
use App\Module\Party\Domain\PartyKind;
use App\Module\Party\Domain\PartyRole;
use App\Module\Party\Domain\PartyRoles;
use App\Module\Party\Domain\PostalAddress;
use App\Shared\Contact\Email;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class PartyTest extends TestCase
{
    public function testAPersonIsNamedGivenNameFirst(): void
    {
        self::assertSame('Erika Muster', self::person()->displayName());
    }

    public function testACompanyHasNoGivenNameInItsName(): void
    {
        $company = new Party(
            10002,
            PartyKind::Company,
            'Musterbau GmbH',
            'Erika Muster',
            PartyRoles::of([PartyRole::Owner]),
            Addresses::of([PostalAddress::of(AddressKind::Street, 'Musterweg 1', '12345', 'Musterstadt')]),
            ContactDetails::of([Email::fromString('kontakt@example.org')]),
        );

        self::assertSame('Musterbau GmbH', $company->displayName());
        self::assertSame('Erika Muster', $company->givenName(), 'Der Ansprechpartner bleibt erhalten');
    }

    /**
     * Gesucht und sortiert wird ueber beide Namensteile. Nur den Nachnamen zu
     * durchsuchen waere in einer Liste von Menschen wenig hilfreich.
     */
    public function testTheSortNameCoversBothParts(): void
    {
        self::assertSame('muster erika', self::person()->sortName());
    }

    public function testSeveralRolesAtOnce(): void
    {
        $party = self::person([PartyRole::Owner, PartyRole::Tenant]);

        self::assertTrue($party->roles()->has(PartyRole::Owner));
        self::assertTrue($party->roles()->has(PartyRole::Tenant));
        self::assertFalse($party->roles()->has(PartyRole::Other));
    }

    public function testTheSameRoleTwiceCountsOnce(): void
    {
        self::assertCount(1, self::person([PartyRole::Tenant, PartyRole::Tenant])->roles()->all());
    }

    public function testRefusesAPartyWithoutARole(): void
    {
        $this->expectException(InvalidArgumentException::class);

        self::person([]);
    }

    public function testRefusesAPartyWithoutAnEmail(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains('E-Mail-Adresse');

        new Party(
            10003, PartyKind::Person, 'Muster', 'Erika', PartyRoles::of([PartyRole::Tenant]),
            Addresses::of([PostalAddress::of(AddressKind::Street, 'Musterweg 1', '12345', 'Musterstadt')]),
            ContactDetails::of([]),
        );
    }

    public function testTheFirstEmailIsTheOneUsedToReachSomeone(): void
    {
        $party = new Party(
            10004, PartyKind::Person, 'Muster', 'Erika', PartyRoles::of([PartyRole::Tenant]),
            Addresses::of([PostalAddress::of(AddressKind::Street, 'Musterweg 1', '12345', 'Musterstadt')]),
            ContactDetails::of([Email::fromString('Erika@Example.ORG'), Email::fromString('zweit@example.org')]),
        );

        self::assertSame('erika@example.org', $party->contact()->primary(), 'Normalisiert wie überall');
        self::assertCount(2, $party->contact()->emails());
    }

    public function testEmptyPhoneNumbersAreDropped(): void
    {
        $party = self::person(phones: ['030 123456', '   ', '', ' 0170 1234 ']);

        self::assertSame(['030 123456', '0170 1234'], $party->contact()->phones());
    }

    public function testAPoBoxAddressHasNoStreet(): void
    {
        $party = new Party(
            10005, PartyKind::Person, 'Muster', 'Erika', PartyRoles::of([PartyRole::Other]),
            Addresses::of([PostalAddress::of(AddressKind::PoBox, 'Postfach 12 34', '12345', 'Musterstadt')]),
            ContactDetails::of([Email::fromString('erika@example.org')]),
        );

        self::assertTrue($party->addresses()->primary()->isPoBox());
        self::assertSame('Postfach 12 34, 12345 Musterstadt', $party->addresses()->primary()->oneLine());
    }

    public function testTheReferenceSurvivesEveryChange(): void
    {
        $party = self::person();
        $party->rename('Neu', 'Erika');
        $party->assignRoles(PartyRoles::of([PartyRole::Owner]));
        $party->moveTo(Addresses::of([PostalAddress::of(AddressKind::PoBox, 'Postfach 9', '54321', 'Anderstadt')]));

        self::assertSame(10001, $party->reference());
    }

    /**
     * @param list<PartyRole> $roles
     * @param list<string>    $phones
     */
    private static function person(array $roles = [PartyRole::Tenant], array $phones = []): Party
    {
        return new Party(
            10001,
            PartyKind::Person,
            'Muster',
            'Erika',
            PartyRoles::of($roles),
            Addresses::of([PostalAddress::of(AddressKind::Street, 'Musterweg 1', '12345', 'Musterstadt')]),
            ContactDetails::of([Email::fromString('erika@example.org')], $phones),
        );
    }
}
