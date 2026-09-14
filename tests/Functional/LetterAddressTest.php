<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Module\Party\Contract\PartyDirectory;
use App\Module\Party\Domain\Addresses;
use App\Module\Party\Domain\AddressKind;
use App\Module\Party\Domain\ContactDetails;
use App\Module\Party\Domain\Party;
use App\Module\Party\Domain\PartyKind;
use App\Module\Party\Domain\PartyRepository;
use App\Module\Party\Domain\PartyRole;
use App\Module\Party\Domain\PartyRoles;
use App\Module\Party\Domain\PostalAddress;
use App\Shared\Contact\Email;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Das Anschriftfeld entsteht aus den Stammdaten — Zeile fuer Zeile.
 *
 * Die einzeilige Form ist fuer Listen; auf ein Kuvert gehoeren vier Zeilen.
 * Wer sie zusammenzieht, bekommt ein Schreiben zurueck, das im Fenster
 * richtig aussah und beim Zusteller nicht mehr.
 *
 * Zusammengesetzt wird im Stammdatenmodul: dass bei einer Firma der
 * Ansprechpartner in die Zusatzzeile gehoert, weiss es — und kein anderes
 * soll es wissen muessen.
 */
final class LetterAddressTest extends WebTestCase
{
    private const int PERSON = 98801;
    private const int COMPANY = 98802;

    protected function tearDown(): void
    {
        self::removeTheParties();

        parent::tearDown();
    }

    /** Zusatz, Strasse und „Postleitzahl Ort" — jedes auf seiner Zeile. */
    public function testAPersonWithAnAdditionGetsFourLines(): void
    {
        self::createClient();
        $party = self::aPerson('c/o Hausverwaltung Nord');

        $brief = self::directory()->byIds([$party->id()])[$party->id()] ?? null;
        self::assertNotNull($brief);

        self::assertSame(
            ['c/o Hausverwaltung Nord', 'Lindenallee 8', '40233 Düsseldorf'],
            $brief->postalLines,
        );
        // Die einzeilige Form bleibt daneben bestehen — Listen brauchen sie.
        self::assertSame('Lindenallee 8, 40233 Düsseldorf', $brief->address);
    }

    /** Ohne Zusatz sind es drei Zeilen und keine leere dazwischen. */
    public function testWithoutAnAdditionThereIsNoEmptyLine(): void
    {
        self::createClient();
        $party = self::aPerson('');

        $brief = self::directory()->byIds([$party->id()])[$party->id()] ?? null;
        self::assertNotNull($brief);

        self::assertSame(['Lindenallee 8', '40233 Düsseldorf'], $brief->postalLines);
    }

    /**
     * Bei einer Firma steht der Ansprechpartner in der Zusatzzeile.
     *
     * In Zeile eins die Firma — an sie ist der Brief gerichtet. „z. Hd."
     * darunter sagt, wer ihn dort aufmachen soll.
     */
    public function testACompanyPutsItsContactIntoTheAdditionLine(): void
    {
        self::createClient();
        $party = self::aCompany();

        $brief = self::directory()->byIds([$party->id()])[$party->id()] ?? null;
        self::assertNotNull($brief);

        self::assertSame('Ladenbetrieb GmbH', $brief->displayName);
        self::assertSame(
            ['z. Hd. Frau Schneider', 'Hinterhaus, 2. OG', 'Geschäftsweg 7', '40213 Düsseldorf'],
            $brief->postalLines,
        );
    }

    private static function aPerson(string $addition): Party
    {
        $party = new Party(
            self::PERSON,
            PartyKind::Person,
            'Wagner',
            'Tobias',
            PartyRoles::of([PartyRole::Owner]),
            Addresses::of([
                PostalAddress::of(AddressKind::Street, 'Lindenallee 8', '40233', 'Düsseldorf', $addition),
            ]),
            ContactDetails::of([Email::fromString('tobias@example.org')]),
        );
        self::parties()->save($party);

        return $party;
    }

    private static function aCompany(): Party
    {
        $party = new Party(
            self::COMPANY,
            PartyKind::Company,
            'Ladenbetrieb GmbH',
            'Frau Schneider',
            PartyRoles::of([PartyRole::Tenant]),
            Addresses::of([
                PostalAddress::of(AddressKind::Street, 'Geschäftsweg 7', '40213', 'Düsseldorf', 'Hinterhaus, 2. OG'),
            ]),
            ContactDetails::of([Email::fromString('laden@example.org')]),
        );
        self::parties()->save($party);

        return $party;
    }

    private static function directory(): PartyDirectory
    {
        $directory = self::getContainer()->get(PartyDirectory::class);
        self::assertInstanceOf(PartyDirectory::class, $directory);

        return $directory;
    }

    private static function parties(): PartyRepository
    {
        $parties = self::getContainer()->get(PartyRepository::class);
        self::assertInstanceOf(PartyRepository::class, $parties);

        return $parties;
    }

    private static function removeTheParties(): void
    {
        if (null === self::$kernel) {
            return;
        }

        $entityManager = self::getContainer()->get(EntityManagerInterface::class);

        if ($entityManager instanceof EntityManagerInterface) {
            $entityManager->createQuery('DELETE FROM '.Party::class.' p WHERE p.reference IN (:refs)')
                ->setParameter('refs', [self::PERSON, self::COMPANY])
                ->execute();
        }
    }
}
