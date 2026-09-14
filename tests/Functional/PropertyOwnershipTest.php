<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Module\Party\Domain\Addresses;
use App\Module\Party\Domain\AddressKind;
use App\Module\Party\Domain\ContactDetails;
use App\Module\Party\Domain\Party;
use App\Module\Party\Domain\PartyKind;
use App\Module\Party\Domain\PartyRepository;
use App\Module\Party\Domain\PartyRole;
use App\Module\Party\Domain\PartyRoles;
use App\Module\Party\Domain\PostalAddress;
use App\Module\Property\Application\AssignOwners;
use App\Module\Property\Domain\Management;
use App\Module\Property\Domain\ManagementMode;
use App\Module\Property\Domain\ManagementModes;
use App\Module\Property\Domain\Mea;
use App\Module\Property\Domain\MeaAlreadyDistributed;
use App\Module\Property\Domain\MeaDenominator;
use App\Module\Property\Domain\Property;
use App\Module\Property\Domain\PropertyPermissions;
use App\Module\Property\Domain\PropertyRepository;
use App\Module\Property\Domain\Unit;
use App\Module\Property\Domain\UnitOwner;
use App\Shared\Contact\Email;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Eigentum — und was es fuer die Stammdaten bedeutet.
 *
 * Die Loeschsperre der Stammdaten steht seit dem Stammdaten-Modul da und
 * hatte bis jetzt nur eine Attrappe als Nachweis. Hier haengt zum ersten Mal
 * etwas Echtes daran.
 */
final class PropertyOwnershipTest extends WebTestCase
{
    use ForgetsRateLimits;
    use SignsIn;

    private const string NAME = 'Eigentumsobjekt';

    private const string OWNER = 'Eigentümerin Prüfung';

    private const string SECOND_OWNER = 'Zweiteigentümer Prüfung';

    protected function tearDown(): void
    {
        self::cleanUp();
        self::removeTestUser();

        parent::tearDown();
    }

    /** Wer eine Einheit besitzt, laesst sich nicht mehr loeschen. */
    public function testAContactThatOwnsAUnitCannotBeDeleted(): void
    {
        $client = self::manager([PropertyPermissions::VIEW, 'parties.view', 'parties.delete']);
        $party = self::givenOwnedUnit();

        $client->request('POST', '/stammdaten/'.$party->reference().'/loeschen', ['_token' => 'egal']);

        self::assertNotNull(
            self::parties()->byId($party->id()),
            'Ein Kontakt mit Eigentum bleibt stehen',
        );
    }

    /** Und die Uebersicht sagt, warum der Knopf gesperrt ist. */
    public function testTheContactPageNamesTheReason(): void
    {
        $client = self::manager([PropertyPermissions::VIEW, 'parties.view']);
        $party = self::givenOwnedUnit();

        $client->request('GET', '/stammdaten/'.$party->reference());

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Eigentümer einer Einheit', (string) $client->getResponse()->getContent());
    }

    /** Die Vervollstaendigung findet ueber Namen und Nummer. */
    public function testThePickerFindsByNameAndByNumber(): void
    {
        $client = self::manager([PropertyPermissions::VIEW, PropertyPermissions::EDIT]);
        $party = self::givenParty();

        self::assertSame([self::OWNER], self::namesFound($client, 'Prüfung'));
        self::assertSame([self::OWNER], self::namesFound($client, (string) $party->reference()));
    }

    /** Ohne Eingabe keine Liste — die ersten zehn Kontakte sind keine Hilfe. */
    public function testThePickerStaysQuietWithoutATerm(): void
    {
        $client = self::manager([PropertyPermissions::VIEW, PropertyPermissions::EDIT]);
        self::givenParty();

        $client->request('GET', '/objekte/eigentuemer/suche?q=');

        self::assertSame('{"results":[]}', (string) $client->getResponse()->getContent());
    }

    /** Ein Objekt zu loeschen nimmt seine Einheiten mit. */
    /** Ein Entwurf verschwindet mit allem, was daran hängt. */
    public function testDeletingADraftTakesItsUnitsWithIt(): void
    {
        $client = self::manager([PropertyPermissions::VIEW, PropertyPermissions::DELETE]);
        self::givenOwnedUnit();
        $property = self::property();
        $property->managedAs(Management::draft());
        self::properties()->save($property);
        $number = $property->number();

        $crawler = $client->request('GET', '/objekte');
        $token = $crawler->filter('form[action$="/objekte/'.$number.'/loeschen"] input[name="_token"]');
        self::assertGreaterThan(0, $token->count());

        $client->request('POST', '/objekte/'.$number.'/loeschen', ['_token' => (string) $token->attr('value')]);

        self::assertResponseRedirects('/objekte');
        self::assertSame(0, self::countUnits(), 'Die Einheiten sind mitgegangen');
    }

    /**
     * Was einmal verwaltet wurde, wird nicht gelöscht.
     *
     * Abgerechnet wird das vergangene Jahr, manchmal das vorletzte — dafür
     * braucht es das Objekt noch. Auch an der Oberfläche vorbei.
     */
    public function testAManagedPropertyIsNotDeleted(): void
    {
        $client = self::manager([PropertyPermissions::VIEW, PropertyPermissions::DELETE]);
        self::givenOwnedUnit();
        $number = self::property()->number();

        $crawler = $client->request('GET', '/objekte');
        $token = (string) $crawler->filter('main input[name="_token"]')->first()->attr('value');
        $client->request('POST', '/objekte/'.$number.'/loeschen', ['_token' => $token]);

        self::assertNotNull(self::propertyByName(), 'Das Objekt steht noch da');
    }

    /**
     * Einen Eigentuemer aendern und dabei einen zweiten aufnehmen.
     *
     * Der Fall, an dem die erste Fassung scheiterte: sie warf alle Zeilen weg
     * und legte sie neu an. Beim Speichern lag die neue Zeile fuer denselben
     * Kontakt vor der geloeschten alten, und der eindeutige Index
     * (Einheit, Kontakt) sagte nein — mit einem 500er mitten im Ablauf.
     */
    public function testChangingOneOwnerWhileAddingAnother(): void
    {
        self::manager([PropertyPermissions::VIEW, PropertyPermissions::EDIT]);
        $first = self::givenParty();
        $unit = self::givenUnit();
        self::owners()->to($unit, Mea::of('50', 1000), self::rows([$first->id() => '50']));

        $second = self::givenSecondParty();
        self::owners()->to($unit, Mea::of('50', 1000), self::rows([$first->id() => '30', $second->id() => '20'], $unit));

        self::assertSame(
            ['30/1000', '20/1000'],
            array_map(static fn (UnitOwner $o): string => $o->mea()->toString(), $unit->owners()),
        );
        self::assertTrue($unit->everHeldByOwners()->equals($unit->mea()));
    }

    /** Und wer nicht mehr im Formular steht, verschwindet. */
    public function testAnOwnerLeftOutIsRemoved(): void
    {
        self::manager([PropertyPermissions::VIEW, PropertyPermissions::EDIT]);
        $first = self::givenParty();
        $second = self::givenSecondParty();
        $unit = self::givenUnit();
        self::owners()->to($unit, Mea::of('50', 1000), self::rows([$first->id() => '30', $second->id() => '20']));

        self::owners()->to($unit, Mea::of('50', 1000), self::rows([$second->id() => '50'], $unit));

        self::assertSame(
            [$second->id()],
            array_map(static fn (UnitOwner $o): string => $o->partyId(), $unit->owners()),
        );
    }

    /**
     * Ein Eigentuemeranteil sperrt den Nennerwechsel, auch wenn die Einheit
     * selbst noch bei null steht.
     *
     * Genau so entsteht er im Ablauf: der Schritt nimmt die Eigentuemer
     * entgegen, bevor jemand den Anteil der Einheit abgetippt hat. Wer dann
     * den Nenner von 1000 auf 10000 zoege, haette aus 100/1000 ein 100/10000
     * gemacht, ohne dass eine Zahl sich sichtbar geaendert haette.
     */
    public function testAnOwnerShareLocksTheDenominatorEvenWhenTheUnitHasNone(): void
    {
        self::manager([PropertyPermissions::VIEW, PropertyPermissions::EDIT]);
        $party = self::givenParty();
        $unit = self::givenUnit();

        self::owners()->to($unit, Mea::none(1000), self::rows([$party->id() => '100']));

        self::assertTrue($unit->mea()->isZero(), 'Die Einheit selbst haelt nichts');
        self::assertFalse($unit->everHeldByOwners()->isZero(), 'Der Eigentuemer schon');

        $this->expectException(MeaAlreadyDistributed::class);
        $unit->property()->scaleMeaTo(MeaDenominator::TenThousand);
    }

    /**
     * Eine Kennung ohne Stammdatensatz wird abgelehnt — im Ablauf, nicht erst
     * beim Anzeigen.
     *
     * Aufgehalten wuerde es am Ende auch vom Fremdschluessel (siehe
     * OwnerIntegrityTest) — aber als Datenbankfehler mitten auf der Seite.
     * Hier kommt der Schritt mit einer Meldung zurueck.
     */
    public function testAnInventedContactIsRefusedByTheStep(): void
    {
        $client = self::manager([PropertyPermissions::VIEW, PropertyPermissions::EDIT]);
        $unit = self::givenUnit();
        self::properties()->save($unit->property());
        $url = '/objekte/'.$unit->property()->number().'/einheiten/'.$unit->number().'/bearbeiten/eigentuemer';

        $crawler = $client->request('GET', $url);
        $token = $crawler->filter('main input[name="_token"]')->first()->attr('value');

        $client->request('POST', $url, [
            '_token' => (string) $token,
            'mea' => '50',
            'owners' => ['neu-1' => [
                'party' => '11111111-2222-3333-4444-555555555555',
                'mea' => '50', 'von' => '', 'bis' => '',
            ]],
        ]);

        self::assertResponseIsSuccessful();
        self::assertStringContainsString(
            'nicht auffindbar',
            (string) $client->getResponse()->getContent(),
        );
        self::assertSame(0, self::countOwners(), 'Nichts wurde uebernommen');
    }

    /**
     * Die Kurzform des Tests in die Form des Formulars.
     *
     * Dort steht je Zeile der Kontakt, sein Anteil und sein Zeitraum — und
     * der Schluessel ist die Zeile und nicht der Kontakt. Wo der Zeitraum
     * nichts zur Sache tut, bleibt er offen: „schon immer und noch".
     *
     * Steht die Zeile schon an der Einheit, kommt sie mit **ihrer** Kennung
     * zurueck — so wie das Formular sie zeichnet. Nur so erkennt die
     * Zuordnung sie wieder; mit einem frischen Schluessel entstuende eine
     * zweite Zeile fuer denselben Zeitraum.
     *
     * @param array<string, string> $shares Kennung des Kontakts auf Zaehler
     *
     * @return array<string, array{party: string, mea: string, von: string, bis: string}>
     */
    /**
     * Der Rueckweg nach einem Fehler behaelt die Schluessel der offenen Zeilen.
     *
     * Sonst heisst die naechste hinzugefuegte Zeile genauso wie die schon
     * gezeichnete, und beim Absenden ueberschreibt eine die andere. Der Fall
     * entsteht nur ueber den Fehlerweg: eine ungespeicherte Zeile steht dann
     * erneut da, und daneben kommt eine zweite dazu.
     */
    public function testAfterAnErrorASecondNewRowDoesNotCollide(): void
    {
        $client = self::manager([PropertyPermissions::VIEW, PropertyPermissions::EDIT]);
        $first = self::givenParty();
        $second = self::givenSecondParty();
        $unit = self::givenUnit();
        self::properties()->save($unit->property());
        $url = '/objekte/'.$unit->property()->number().'/einheiten/'.$unit->number().'/bearbeiten/eigentuemer';

        // Ein unbekannter Kontakt laesst den Schritt mit einem Fehler zurueck
        // — und die eingetippte Zeile steht wieder da.
        $crawler = $client->request('GET', $url);
        $crawler = $client->request('POST', $url, [
            '_token' => (string) $crawler->filter('main input[name="_token"]')->first()->attr('value'),
            'mea' => '50',
            'owners' => [
                'neu-1' => ['party' => $first->id(), 'mea' => '25', 'von' => '', 'bis' => ''],
                'neu-2' => ['party' => '11111111-2222-3333-4444-555555555555', 'mea' => '25', 'von' => '', 'bis' => ''],
            ],
        ]);

        self::assertResponseIsSuccessful();
        self::assertSame(
            ['neu-1', 'neu-2'],
            // Ohne die Vorlage: die steht im <template> und ist keine Zeile.
            $crawler->filter('[data-party-list] .ib-picker__row')->extract(['data-party-row']),
            'Die offenen Zeilen behalten ihre Schlüssel',
        );

        // Und eine dritte Zeile, wie das Skript sie anlegen wuerde: der
        // naechste freie Schluessel ist neu-3 und nicht wieder neu-1.
        $client->request('POST', $url, [
            '_token' => (string) $crawler->filter('main input[name="_token"]')->first()->attr('value'),
            'mea' => '50',
            'owners' => [
                'neu-1' => ['party' => $first->id(), 'mea' => '25', 'von' => '', 'bis' => ''],
                'neu-3' => ['party' => $second->id(), 'mea' => '25', 'von' => '', 'bis' => ''],
            ],
        ]);

        self::assertResponseRedirects();
        self::assertSame(2, self::countOwners(), 'Zwei Zeilen, keine hat die andere verdrängt');
    }

    /**
     * Verkauf und Rueckkauf ueber den Bearbeiten-Schritt.
     *
     * Der Weg, auf dem es der Mensch tut: zwei Zeilen fuer dieselbe Partei,
     * mit Zeitraeumen, die sich nicht ueberschneiden. Ueber den Kontakt
     * zugeordnet waere das eine Zeile, und die zweite ueberschriebe die erste.
     */
    public function testTheStepCanRecordASaleAndABuyBack(): void
    {
        $client = self::manager([PropertyPermissions::VIEW, PropertyPermissions::EDIT]);
        $party = self::givenParty();
        $unit = self::givenUnit();
        self::owners()->to($unit, Mea::of('50', 1000), self::rows([$party->id() => '50']));
        self::properties()->save($unit->property());

        $url = '/objekte/'.$unit->property()->number().'/einheiten/'.$unit->number().'/bearbeiten/eigentuemer';
        $crawler = $client->request('GET', $url);
        $existing = (string) $crawler->filter('.ib-picker__row')->first()->attr('data-party-row');

        $client->request('POST', $url, [
            '_token' => (string) $crawler->filter('main input[name="_token"]')->first()->attr('value'),
            'mea' => '50',
            'owners' => [
                $existing => ['party' => $party->id(), 'mea' => '50', 'von' => '', 'bis' => '2026-06-30'],
                'neu-1' => ['party' => $party->id(), 'mea' => '50', 'von' => '2026-10-01', 'bis' => ''],
            ],
        ]);

        self::assertResponseRedirects();
        self::assertSame(2, self::countOwners(), 'Verkauft und zurückgekauft sind zwei Zeilen');
    }

    protected static function testEmail(): string
    {
        return 'eigentum@example.org';
    }

    private static function countOwners(): int
    {
        $count = self::entityManager()->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM property_unit_owner',
        );

        self::assertIsNumeric($count);

        return (int) $count;
    }

    /**
     * Die Namen aus der Vervollstaendigung.
     *
     * Ueber den entschluesselten JSON und nicht ueber die Zeichenkette: dort
     * stehen Umlaute als \u00fc, und ein Test, der das vergleicht, prueft die
     * Kodierung statt des Treffers.
     *
     * @return list<string>
     */
    private static function namesFound(KernelBrowser $client, string $term): array
    {
        $client->request('GET', '/objekte/eigentuemer/suche?q='.urlencode($term));

        $payload = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($payload);
        self::assertArrayHasKey('results', $payload);
        self::assertIsArray($payload['results']);

        return array_values(array_map(
            static fn (mixed $row): string => \is_array($row) && \is_string($row['name'] ?? null)
                ? trim(str_replace('Petra', '', $row['name']))
                : '',
            $payload['results'],
        ));
    }

    /**
     * @param list<string> $permissions
     */
    private static function manager(array $permissions): KernelBrowser
    {
        return self::signedInWith($permissions);
    }

    private static function givenOwnedUnit(): Party
    {
        $party = self::givenParty();
        $unit = self::givenUnit();
        $unit->holdShare(Mea::of('1000', 1000));
        new UnitOwner($unit, $party->id(), Mea::of('1000', 1000));

        self::properties()->save($unit->property());

        return $party;
    }

    private static function givenUnit(): Unit
    {
        $property = self::property();

        foreach ($property->units() as $unit) {
            return $unit;
        }

        $unit = new Unit($property, 'WE 1');
        self::properties()->save($property);

        return $unit;
    }

    private static function givenSecondParty(): Party
    {
        return self::partyNamed(self::SECOND_OWNER, 'Tobias', 'zweiter@example.org');
    }

    private static function owners(): AssignOwners
    {
        $owners = self::getContainer()->get(AssignOwners::class);
        self::assertInstanceOf(AssignOwners::class, $owners);

        return $owners;
    }

    private static function property(): Property
    {
        $existing = self::propertyByName();

        if (null !== $existing) {
            return $existing;
        }

        $property = new Property(
            self::properties()->nextNumber(),
            self::NAME,
            ManagementModes::of([ManagementMode::Weg]),
        );
        $property->managedAs($property->management()->activated());
        self::properties()->save($property);

        return $property;
    }

    private static function propertyByName(): ?Property
    {
        $id = self::entityManager()->getConnection()->fetchOne(
            'SELECT id FROM property WHERE name = ?',
            [self::NAME],
        );

        return \is_string($id) ? self::properties()->byId($id) : null;
    }

    private static function givenParty(): Party
    {
        return self::partyNamed(self::OWNER, 'Petra', 'eigentuemerin@example.org');
    }

    private static function partyNamed(string $name, string $given, string $email): Party
    {
        $existing = self::parties()->search($name, 1);

        if ([] !== $existing) {
            return $existing[0];
        }

        $party = new Party(
            self::parties()->nextReference(),
            PartyKind::Person,
            $name,
            $given,
            PartyRoles::of([PartyRole::Owner]),
            Addresses::of([PostalAddress::of(AddressKind::Street, 'Musterweg 2', '12345', 'Musterstadt')]),
            ContactDetails::of([Email::fromString($email)]),
        );
        self::parties()->save($party);

        return $party;
    }

    private static function countUnits(): int
    {
        $count = self::entityManager()->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM property_unit u JOIN property p ON p.id = u.property_id WHERE p.name = ?',
            [self::NAME],
        );

        return is_numeric($count) ? (int) $count : -1;
    }

    private static function cleanUp(): void
    {
        if (null === self::$kernel) {
            return;
        }

        $connection = self::entityManager()->getConnection();
        $connection->executeStatement('DELETE FROM property WHERE name = ?', [self::NAME]);
        $connection->executeStatement('DELETE FROM party WHERE name IN (?, ?)', [self::OWNER, self::SECOND_OWNER]);
    }

    private static function properties(): PropertyRepository
    {
        $properties = self::getContainer()->get(PropertyRepository::class);
        self::assertInstanceOf(PropertyRepository::class, $properties);

        return $properties;
    }

    private static function parties(): PartyRepository
    {
        $parties = self::getContainer()->get(PartyRepository::class);
        self::assertInstanceOf(PartyRepository::class, $parties);

        return $parties;
    }

    private static function entityManager(): EntityManagerInterface
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);

        return $entityManager;
    }

    /**
     * Die Kurzform des Tests in die Form des Formulars.
     *
     * Dort steht je Zeile der Kontakt, sein Anteil und sein Zeitraum — und
     * der Schluessel ist die Zeile und nicht der Kontakt. Steht die Zeile
     * schon an der Einheit, kommt sie mit **ihrer** Kennung zurueck, so wie
     * das Formular sie zeichnet; mit einem frischen Schluessel entstuende
     * eine zweite Zeile fuer denselben Zeitraum.
     *
     * @param array<string, string> $shares Kennung des Kontakts auf Zaehler
     *
     * @return array<string, array{party: string, mea: string, von: string, bis: string}>
     */
    private static function rows(array $shares, ?Unit $unit = null): array
    {
        $known = [];

        foreach (null === $unit ? [] : $unit->owners() as $owner) {
            $known[$owner->partyId()] = $owner->id();
        }

        $rows = [];
        $at = 0;

        foreach ($shares as $partyId => $mea) {
            ++$at;
            $key = $known[$partyId] ?? 'neu-'.$at;
            $rows[$key] = ['party' => $partyId, 'mea' => $mea, 'von' => '', 'bis' => ''];
        }

        return $rows;
    }
}
