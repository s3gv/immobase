<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Tests\Module\Party\Infrastructure;

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
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Die Vergabe der Referenznummer.
 *
 * Sie war einmal ein MAX(reference) + 1 und ein spaeteres Speichern. Zwischen
 * beidem liegt eine Luecke, und zwei gleichzeitig abgeschickte Abläufe lasen
 * darin dieselbe Nummer.
 */
final class PartyReferenceTest extends KernelTestCase
{
    protected function tearDown(): void
    {
        self::removeTestParties();

        parent::tearDown();
    }

    public function testTheFirstNumberLooksLikeAnIdentifier(): void
    {
        self::assertGreaterThanOrEqual(10001, self::parties()->nextReference());
    }

    /**
     * Der Fall, an dem die alte Vergabe scheiterte: beide Abläufe ziehen ihre
     * Nummer, bevor einer von beiden speichert. Mit MAX(reference) + 1 waren
     * das zweimal dieselbe, und das zweite Speichern lief in den eindeutigen
     * Index.
     */
    public function testTwoFlowsDrawingAtOnceGetDifferentNumbers(): void
    {
        $parties = self::parties();

        $first = $parties->nextReference();
        $second = $parties->nextReference();

        self::assertNotSame($first, $second, 'Zweimal dieselbe Nummer');

        $parties->save(self::party($first));
        $parties->save(self::party($second));

        self::assertNotNull($parties->byReference($first));
        self::assertNotNull($parties->byReference($second));
    }

    /**
     * Und die Nummern zaehlen weiter, statt eine vergebene noch einmal
     * auszugeben.
     */
    public function testTheNextNumberIsAboveEveryStoredOne(): void
    {
        $parties = self::parties();
        $taken = $parties->nextReference();
        $parties->save(self::party($taken));

        self::assertGreaterThan($taken, $parties->nextReference());
    }

    private static function parties(): PartyRepository
    {
        $parties = self::getContainer()->get(PartyRepository::class);
        self::assertInstanceOf(PartyRepository::class, $parties);

        return $parties;
    }

    private static function party(int $reference): Party
    {
        return new Party(
            $reference,
            PartyKind::Person,
            'Nummernprobe',
            'Erika',
            PartyRoles::of([PartyRole::Tenant]),
            Addresses::of([PostalAddress::of(AddressKind::Street, 'Musterweg 1', '12345', 'Musterstadt')]),
            ContactDetails::of([Email::fromString('nummernprobe@example.org')]),
        );
    }

    private static function removeTestParties(): void
    {
        if (null === self::$kernel) {
            return;
        }

        $entityManager = self::getContainer()->get(EntityManagerInterface::class);

        if (!$entityManager instanceof EntityManagerInterface) {
            return;
        }

        $entityManager->createQuery('DELETE FROM '.Party::class.' p WHERE p.partyName.name = :name')
            ->setParameter('name', 'Nummernprobe')
            ->execute();
    }
}
