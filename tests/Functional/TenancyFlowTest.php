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
use App\Module\Property\Domain\ManagementMode;
use App\Module\Property\Domain\ManagementModes;
use App\Module\Property\Domain\Property;
use App\Module\Property\Domain\PropertyRepository;
use App\Module\Property\Domain\Unit;
use App\Module\Tenancy\Domain\Tenancy;
use App\Module\Tenancy\Domain\TenancyPermissions;
use App\Module\Tenancy\Domain\TenancyRepository;
use App\Module\Tenancy\Domain\TenancyStatus;
use App\Module\Tenancy\UserInterface\Controller\TenancyFlow;
use App\Shared\Contact\Email;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Ein Mietverhaeltnis anlegen — und was dabei nicht durchgeht.
 *
 * Wie beim Objekt speichert jeder Schritt sofort. Der Unterschied: hier
 * beginnt der Datensatz als Entwurf, und erst der letzte Schritt macht die
 * Einheit vermietet.
 */
final class TenancyFlowTest extends WebTestCase
{
    use ForgetsRateLimits;
    use SignsIn;

    private const string PROPERTY = 'Mietobjekt Prüfung';

    private const string TENANT = 'Mietende Prüfung';

    protected function tearDown(): void
    {
        self::cleanUp();
        self::removeTestUser();

        parent::tearDown();
    }

    /** Schritt eins legt einen Entwurf an. */
    public function testTheFirstStepCreatesADraft(): void
    {
        $client = self::manager();
        $unit = self::givenUnit();

        self::post($client, '/miete/neu', ['unitId' => $unit->id()]);

        $tenancy = self::found();
        self::assertSame(TenancyStatus::Draft, $tenancy->status());
        self::assertGreaterThanOrEqual(30001, $tenancy->number());
        self::assertSame($unit->id(), $tenancy->unitId());
    }

    /**
     * Ohne Mieter laesst sich nicht abschliessen.
     *
     * Aktiv heisst: die Einheit ist vermietet, und zwar an jemanden. Waehrend
     * der Erfassung darf es leer bleiben — deshalb beginnt es als Entwurf.
     */
    public function testItCannotBeCompletedWithoutATenant(): void
    {
        $client = self::manager();
        self::givenTenancy($client);
        $number = self::found()->number();

        self::post($client, '/miete/'.$number.'/bearbeiten/'.TenancyFlow::NOTE, ['direction' => 'forward']);

        self::assertResponseIsSuccessful();
        self::assertSame(TenancyStatus::Draft, self::found()->status());
        self::assertStringContainsString(
            'mindestens einen Mieter',
            (string) $client->getResponse()->getContent(),
        );
    }

    public function testTheLastStepActivatesIt(): void
    {
        $client = self::manager();
        self::givenTenancy($client);
        $number = self::found()->number();
        self::givenTenant($client, $number);

        self::post($client, '/miete/'.$number.'/bearbeiten/'.TenancyFlow::NOTE, ['direction' => 'forward']);

        self::assertResponseRedirects('/miete/'.$number);
        self::assertSame(TenancyStatus::Active, self::found()->status());
    }

    /**
     * Eine Einheit hat hoechstens ein aktives Mietverhaeltnis.
     *
     * „Leerstand per Ausschlussprinzip" traegt nur, wenn „aktiv" je Einheit
     * eindeutig ist. Der Entwurf daneben stoert das nicht — er blockiert
     * nichts und gilt nirgends als Vermietung.
     */
    public function testAUnitCannotBeLetTwice(): void
    {
        $client = self::manager();
        $unit = self::givenUnit();
        self::givenActiveTenancy($client);

        self::post($client, '/miete/neu', ['unitId' => $unit->id()]);
        $successor = self::latest();
        self::givenTenant($client, $successor->number());
        self::post($client, '/miete/'.$successor->number().'/bearbeiten/'.TenancyFlow::NOTE, [
            'direction' => 'forward',
        ]);

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('bereits vermietet', (string) $client->getResponse()->getContent());
        self::assertSame(TenancyStatus::Draft, self::latest()->status(), 'Das zweite blieb ein Entwurf');
    }

    /**
     * Eine ueberlappende Laufzeit endet als Meldung, nicht als 500.
     *
     * Die Absage faellt im letzten Schritt auf, und der Ablauf muss sie
     * genauso auffangen wie „bereits vermietet" — sonst sieht der Benutzer
     * eine Fehlerseite fuer etwas, das er verstehen und aendern kann.
     */
    public function testAnOverlappingTermIsRefusedInTheFlow(): void
    {
        $client = self::manager();
        $unit = self::givenUnit();
        self::givenActiveTenancy($client);
        $first = self::found()->number();
        self::post($client, '/miete/'.$first.'/beenden', ['endsOn' => '2026-06-30']);

        self::post($client, '/miete/neu', ['unitId' => $unit->id()]);
        $successor = self::latest()->number();
        self::givenTenant($client, $successor);
        self::post($client, '/miete/'.$successor.'/bearbeiten/'.TenancyFlow::TERM, [
            'startsOn' => '2026-06-01',
            'direction' => 'forward',
        ]);
        self::post($client, '/miete/'.$successor.'/bearbeiten/'.TenancyFlow::NOTE, ['direction' => 'forward']);

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('diesem Zeitraum', (string) $client->getResponse()->getContent());
        self::assertSame(TenancyStatus::Draft, self::latest()->status());
    }

    /** Schliesst der Nachmieter nahtlos an, geht es durch. */
    public function testASeamlessSuccessionIsCompleted(): void
    {
        $client = self::manager();
        $unit = self::givenUnit();
        self::givenActiveTenancy($client);
        $first = self::found()->number();
        self::post($client, '/miete/'.$first.'/beenden', ['endsOn' => '2026-06-30']);

        self::post($client, '/miete/neu', ['unitId' => $unit->id()]);
        $successor = self::latest()->number();
        self::givenTenant($client, $successor);
        self::post($client, '/miete/'.$successor.'/bearbeiten/'.TenancyFlow::TERM, [
            'startsOn' => '2026-07-01',
            'direction' => 'forward',
        ]);
        self::post($client, '/miete/'.$successor.'/bearbeiten/'.TenancyFlow::NOTE, ['direction' => 'forward']);

        self::assertSame(TenancyStatus::Active, self::latest()->status());
    }

    /** Der Nachmieter wird erfasst, waehrend der Vormieter noch wohnt. */
    public function testASuccessorMayBeDraftedForALetUnit(): void
    {
        $client = self::manager();
        $unit = self::givenUnit();
        self::givenActiveTenancy($client);

        self::post($client, '/miete/neu', ['unitId' => $unit->id()]);

        self::assertCount(2, self::all(), 'Der Entwurf ist entstanden');
        self::assertTrue(self::latest()->status()->isDraft());
    }

    /** Beendet gibt die Einheit frei — danach geht ein neues. */
    public function testEndingFreesTheUnit(): void
    {
        $client = self::manager();
        $unit = self::givenUnit();
        self::givenTenancy($client);
        $number = self::found()->number();
        self::givenTenant($client, $number);
        self::post($client, '/miete/'.$number.'/bearbeiten/'.TenancyFlow::NOTE, ['direction' => 'forward']);

        self::post($client, '/miete/'.$number.'/beenden', ['endsOn' => '2026-06-30']);
        self::post($client, '/miete/neu', ['unitId' => $unit->id()]);

        self::assertResponseRedirects();
        self::assertCount(2, self::all());
    }

    /**
     * Ohne Mietende wird nicht beendet.
     *
     * Ein Mietverhaeltnis, das beendet ist, aber nicht sagt wann, traegt
     * einen Zeitraum ohne Ende — und ist damit fuer jede tagesgenaue
     * Abrechnung wertlos. Das faellt erst zwei Jahre spaeter auf.
     */
    public function testEndingNeedsADate(): void
    {
        $client = self::manager();
        self::givenActiveTenancy($client);
        $number = self::found()->number();

        self::post($client, '/miete/'.$number.'/beenden', ['endsOn' => '']);

        self::assertSame(TenancyStatus::Active, self::found()->status());
    }

    /** Und das Ende steht danach auch wirklich da. */
    public function testEndingRecordsTheDay(): void
    {
        $client = self::manager();
        self::givenActiveTenancy($client);
        $number = self::found()->number();

        self::post($client, '/miete/'.$number.'/beenden', ['endsOn' => '2026-06-30']);

        $tenancy = self::found();
        self::assertSame(TenancyStatus::Ended, $tenancy->status());
        self::assertSame('2026-06-30', $tenancy->term()->endsOn()?->format('Y-m-d'));
    }

    /**
     * Die Liste zeigt den laufenden Stand — und die Geschichte auf Wunsch.
     *
     * Beim Oeffnen will man wissen, was gilt. Ein Entwurf ist angefangene
     * Arbeit und bleibt sichtbar; ein beendetes Mietverhaeltnis sucht man
     * bewusst.
     */
    public function testTheListHidesEndedTenanciesUntilAsked(): void
    {
        $client = self::manager();
        self::givenActiveTenancy($client);
        $number = self::found()->number();
        self::post($client, '/miete/'.$number.'/beenden', ['endsOn' => '2026-06-30']);

        $client->request('GET', '/miete');
        self::assertStringNotContainsString((string) $number, self::body($client), 'Beendetes steht nicht im Weg');

        $client->request('GET', '/miete?vergangene=1');
        self::assertStringContainsString((string) $number, self::body($client));
    }

    /**
     * Jeder Schritt und jeder Abschnitt zeichnet sich.
     *
     * Twig prueft beim Linten nur die Syntax, nicht die Pfade: eine
     * umgezogene Eigenschaft faellt erst auf, wenn die Seite jemand oeffnet.
     * Das hier ist dieses Jemand.
     */
    public function testEveryStepAndSectionRenders(): void
    {
        $client = self::manager();
        self::givenActiveTenancy($client);
        $number = self::found()->number();

        foreach (TenancyFlow::keys() as $step) {
            $client->request('GET', '/miete/'.$number.'/bearbeiten/'.$step);
            self::assertResponseIsSuccessful('Schritt '.$step);

            $client->request('GET', '/miete/'.$number.'?abschnitt='.$step);
            self::assertResponseIsSuccessful('Abschnitt '.$step);
        }
    }

    /** Ein Entwurf ist loeschbar, ein beendetes Mietverhaeltnis nicht. */
    public function testWhatHasRunIsNotDeleted(): void
    {
        $client = self::manager();
        self::givenActiveTenancy($client);
        $number = self::found()->number();
        self::post($client, '/miete/'.$number.'/beenden', ['endsOn' => '2026-06-30']);

        self::post($client, '/miete/'.$number.'/loeschen', []);

        self::assertCount(1, self::all(), 'Das beendete Mietverhältnis steht noch da');
    }

    /** Eine unlesbare Personenzahl legt keinen Haushaltseintrag an. */
    public function testAFaultyHouseholdSizeIsRefused(): void
    {
        $client = self::manager();
        self::givenTenancy($client);
        $number = self::found()->number();

        self::post($client, '/miete/'.$number.'/haushalt', [
            'startsOn' => '2026-01-01',
            'people' => 'abc',
        ]);

        self::assertTrue(self::found()->household()->isEmpty(), 'Es wurde nichts gespeichert');
    }

    /**
     * Die Personenzahl ist ein Zeitraum, kein Wert.
     *
     * Der Stand von 2026 muss abrufbar bleiben, wenn 2027 schon ein anderer
     * gilt — sonst veraendert ein neues Kind still die Abrechnung des
     * Vorjahres.
     */
    public function testTheHouseholdKeepsItsHistory(): void
    {
        $client = self::manager();
        self::givenTenancy($client);
        $number = self::found()->number();

        self::post($client, '/miete/'.$number.'/haushalt', ['startsOn' => '2026-01-01', 'people' => '2']);
        self::post($client, '/miete/'.$number.'/haushalt', ['startsOn' => '2027-01-01', 'people' => '3']);

        $household = self::found()->household();
        self::assertSame(2, $household->peopleOn(new DateTimeImmutable('2026-07-01')));
        self::assertSame(3, $household->peopleOn(new DateTimeImmutable('2027-07-01')));
    }

    /** Ein Datum, das es nicht gibt, wird nicht in ein anderes umgerechnet. */
    public function testAnImpossibleDateIsRefused(): void
    {
        $client = self::manager();
        self::givenTenancy($client);
        $number = self::found()->number();

        self::post($client, '/miete/'.$number.'/bearbeiten/laufzeit', [
            'startsOn' => '2026-02-30',
            'direction' => 'forward',
        ]);

        self::assertResponseIsSuccessful();
        self::assertNull(self::found()->term()->startsOn(), 'Der 2. März wurde nicht heimlich gespeichert');
    }

    /** Und ein Ende vor dem Beginn ist keine Laufzeit. */
    public function testAnEndBeforeTheStartIsRefused(): void
    {
        $client = self::manager();
        self::givenTenancy($client);
        $number = self::found()->number();

        self::post($client, '/miete/'.$number.'/bearbeiten/laufzeit', [
            'startsOn' => '2026-04-01',
            'endsOn' => '2026-03-01',
            'direction' => 'forward',
        ]);

        self::assertResponseIsSuccessful();
        self::assertNull(self::found()->term()->startsOn());
    }

    /** Eine erfundene Einheit kommt nicht durch. */
    public function testAnInventedUnitIsRefused(): void
    {
        $client = self::manager();
        self::givenUnit();

        self::post($client, '/miete/neu', ['unitId' => '11111111-2222-3333-4444-555555555555']);

        self::assertResponseIsSuccessful();
        self::assertSame([], self::all());
    }

    protected static function testEmail(): string
    {
        return 'miete@example.org';
    }

    private static function manager(): KernelBrowser
    {
        return self::signedInWith([
            TenancyPermissions::VIEW,
            TenancyPermissions::EDIT,
            TenancyPermissions::DELETE,
            'properties.view',
            'properties.edit',
            'parties.view',
        ]);
    }

    private static function givenTenancy(KernelBrowser $client): void
    {
        self::post($client, '/miete/neu', ['unitId' => self::givenUnit()->id()]);
    }

    /** Ein Mietverhaeltnis, das schon laeuft — mit Mieter und Laufzeit. */
    private static function givenActiveTenancy(KernelBrowser $client): void
    {
        self::givenTenancy($client);
        $number = self::found()->number();
        self::givenTenant($client, $number);
        self::post($client, '/miete/'.$number.'/bearbeiten/'.TenancyFlow::TERM, [
            'startsOn' => '2026-01-01',
            'direction' => 'forward',
        ]);
        self::post($client, '/miete/'.$number.'/bearbeiten/'.TenancyFlow::NOTE, ['direction' => 'forward']);
    }

    private static function givenTenant(KernelBrowser $client, int $number): void
    {
        self::post($client, '/miete/'.$number.'/bearbeiten/mieter', [
            'tenants' => [self::givenParty()->id()],
            'direction' => 'forward',
        ]);
    }

    /**
     * @param array<string, mixed> $fields
     */
    private static function post(KernelBrowser $client, string $url, array $fields): void
    {
        $client->request('POST', $url, [...$fields, '_token' => self::tokenFrom($client, $url)]);
    }

    /**
     * Die Seite, auf der das Formular zu dieser Adresse steht.
     *
     * Die Aktionen des Mietverhaeltnisses haben eigene Adressen, die selbst
     * keine Seite sind — das Formular dazu steht auf der Mietseite.
     */
    private static function pageFor(string $url): string
    {
        // Die Haushaltsstaffel steht im Schritt „Mieter" — nur dort traegt
        // sie ihr eigenes Formular und damit ihr eigenes Token.
        if (str_ends_with($url, '/haushalt')) {
            return substr($url, 0, -\strlen('/haushalt')).'/bearbeiten/'.TenancyFlow::TENANTS;
        }

        foreach (['/beenden', '/aktivieren', '/loeschen'] as $action) {
            if (str_ends_with($url, $action)) {
                return substr($url, 0, -\strlen($action));
            }
        }

        return $url;
    }

    /**
     * Das Token steht in dem Formular, das auf diese Adresse zeigt.
     *
     * Aus der Seite geholt und nicht selbst erzeugt: ausserhalb einer Anfrage
     * gibt es keine Sitzung, in der ein Token liegen koennte — und ein Test,
     * der sich sein Token selbst ausstellt, prueft die Pruefung nicht mit.
     */
    private static function tokenFrom(KernelBrowser $client, string $url): string
    {
        $page = self::pageFor($url);
        $crawler = $client->request('GET', $page);
        $field = $crawler->filter('form[action$="'.$url.'"] input[name="_token"]');

        if (0 === $field->count()) {
            $field = $crawler->filter('input[name="_token"]');
        }

        self::assertGreaterThan(0, $field->count(), 'Kein Formular auf '.$page);

        return (string) $field->first()->attr('value');
    }

    /** Das zuletzt angelegte — beim Nachmieter steht das erste noch daneben. */
    private static function latest(): Tenancy
    {
        $all = self::all();

        self::assertNotSame([], $all, 'Es wurde kein Mietverhältnis angelegt.');

        return $all[\count($all) - 1];
    }

    private static function body(KernelBrowser $client): string
    {
        return (string) $client->getResponse()->getContent();
    }

    private static function found(): Tenancy
    {
        $all = self::all();

        self::assertNotSame([], $all, 'Es wurde kein Mietverhältnis angelegt.');

        return $all[0];
    }

    /**
     * @return list<Tenancy>
     */
    private static function all(): array
    {
        self::entityManager()->clear();

        /** @var list<string> $ids */
        $ids = self::entityManager()->getConnection()->fetchFirstColumn(
            'SELECT t.id FROM tenancy t JOIN property_unit u ON u.id = t.unit_id
             JOIN property p ON p.id = u.property_id WHERE p.name = ? ORDER BY t.number',
            [self::PROPERTY],
        );

        return array_values(array_filter(array_map(self::tenancies()->byId(...), $ids)));
    }

    private static function givenUnit(): Unit
    {
        $units = self::propertyByName()?->units() ?? [];

        if ([] !== $units) {
            return $units[0];
        }

        $property = new Property(
            self::properties()->nextNumber(),
            self::PROPERTY,
            ManagementModes::of([ManagementMode::Rental]),
        );
        $unit = new Unit($property, 'WE 1');
        $property->managedAs($property->management()->activated());
        self::properties()->save($property);

        return $unit;
    }

    private static function propertyByName(): ?Property
    {
        $id = self::entityManager()->getConnection()->fetchOne(
            'SELECT id FROM property WHERE name = ?',
            [self::PROPERTY],
        );

        return \is_string($id) ? self::properties()->byId($id) : null;
    }

    private static function givenParty(): Party
    {
        $existing = self::parties()->search(self::TENANT, 1);

        if ([] !== $existing) {
            return $existing[0];
        }

        $party = new Party(
            self::parties()->nextReference(),
            PartyKind::Person,
            self::TENANT,
            'Mia',
            PartyRoles::of([PartyRole::Tenant]),
            Addresses::of([PostalAddress::of(AddressKind::Street, 'Musterweg 4', '12345', 'Musterstadt')]),
            ContactDetails::of([Email::fromString('mieterin@example.org')]),
        );
        self::parties()->save($party);

        return $party;
    }

    private static function cleanUp(): void
    {
        if (null === self::$kernel) {
            return;
        }

        $connection = self::entityManager()->getConnection();
        $connection->executeStatement(
            'DELETE FROM tenancy WHERE unit_id IN (
                 SELECT u.id FROM property_unit u JOIN property p ON p.id = u.property_id WHERE p.name = ?
             )',
            [self::PROPERTY],
        );
        $connection->executeStatement('DELETE FROM property WHERE name = ?', [self::PROPERTY]);
        $connection->executeStatement('DELETE FROM party WHERE name = ?', [self::TENANT]);
    }

    private static function tenancies(): TenancyRepository
    {
        $tenancies = self::getContainer()->get(TenancyRepository::class);
        self::assertInstanceOf(TenancyRepository::class, $tenancies);

        return $tenancies;
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
}
