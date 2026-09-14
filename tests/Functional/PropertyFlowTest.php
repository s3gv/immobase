<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Module\Auth\Domain\User;
use App\Module\Auth\Domain\UserRepository;
use App\Module\Property\Domain\ManagementMode;
use App\Module\Property\Domain\Property;
use App\Module\Property\Domain\PropertyFilter;
use App\Module\Property\Domain\PropertyPermissions;
use App\Module\Property\Domain\PropertyRepository;
use App\Module\Property\Domain\PropertyStatus;
use App\Module\Property\UserInterface\Controller\PropertyFlow;
use App\Module\Property\UserInterface\Controller\PropertyPage;
use App\Shared\Contact\Email;
use App\Shared\Ui\Page;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Ein Objekt anlegen — und zwar so, dass man es liegen lassen kann.
 *
 * Der Unterschied zum Stammdaten-Ablauf: hier speichert jeder Schritt in die
 * Datenbank. Das ist die Entscheidung, an der dieses Modul haengt, und
 * deshalb steht sie hier als Test und nicht nur in der Spec.
 */
final class PropertyFlowTest extends WebTestCase
{
    use ForgetsRateLimits;
    use SignsIn;

    private const string NAME = 'Prüfobjekt Musterweg';

    protected function tearDown(): void
    {
        self::removeProperty();
        self::removeTestUser();

        parent::tearDown();
    }

    /**
     * Der erste Schritt legt an. Ab da steht das Objekt in der Uebersicht —
     * als Entwurf, sichtbar gekennzeichnet.
     */
    public function testTheFirstStepCreatesADraft(): void
    {
        $client = self::manager();

        self::start($client);

        $property = self::found();
        self::assertSame(PropertyStatus::Draft, $property->status());
        self::assertTrue($property->modes()->has(ManagementMode::Weg));
        self::assertGreaterThanOrEqual(20001, $property->number());

        $client->request('GET', '/objekte');
        self::assertStringContainsString(self::NAME, (string) $client->getResponse()->getContent());
        self::assertStringContainsString('Entwurf', (string) $client->getResponse()->getContent());
    }

    /**
     * Der Kern der Entscheidung: eine neue Sitzung findet den Entwurf wieder.
     *
     * Ein Ablauf, der seinen Zwischenstand in der Sitzung haelt, kann das
     * nicht — und genau deshalb liegt er hier in der Datenbank.
     */
    public function testADraftSurvivesANewSession(): void
    {
        $client = self::manager();
        self::start($client);
        $number = self::found()->number();

        self::inANewSession($client);
        $client->request('GET', '/objekte/'.$number.'/bearbeiten/anschrift');

        self::assertResponseIsSuccessful();
        self::assertStringContainsString(self::NAME, (string) $client->getResponse()->getContent());
    }

    /** Der letzte Schritt macht aus dem Entwurf ein Objekt. */
    public function testTheLastStepCompletesIt(): void
    {
        $client = self::manager();
        self::start($client);
        $number = self::found()->number();
        self::givenAUnit($client, $number);

        self::post($client, '/objekte/'.$number.'/bearbeiten/einheiten', ['direction' => 'forward']);

        self::assertResponseRedirects('/objekte/'.$number);
        self::assertSame(PropertyStatus::Active, self::found()->status());
    }

    /**
     * Jeder Schritt und jeder Abschnitt zeichnet sich.
     *
     * Twig prueft beim Linten nur die Syntax, nicht die Pfade: eine
     * umgezogene Eigenschaft faellt erst auf, wenn die Seite jemand oeffnet.
     */
    public function testEveryStepAndSectionRenders(): void
    {
        $client = self::manager();
        self::start($client);
        $property = self::found();
        self::givenAUnit($client, $property->number());

        foreach (PropertyFlow::keys(self::found()) as $step) {
            $client->request('GET', '/objekte/'.$property->number().'/bearbeiten/'.$step);
            self::assertResponseIsSuccessful('Schritt '.$step);
        }

        foreach (PropertyPage::keys(self::found()) as $section) {
            $client->request('GET', '/objekte/'.$property->number().'?abschnitt='.$section);
            self::assertResponseIsSuccessful('Abschnitt '.$section);
        }
    }

    /**
     * Ohne Einheit laesst sich nichts abschliessen.
     *
     * Ein Objekt ohne Einheit laesst sich nicht vermieten, nicht abrechnen
     * und nicht aufteilen. Der Entwurf darf trotzdem leer bleiben — geprueft
     * wird erst am Ende.
     */
    public function testAPropertyWithoutAUnitCannotBeCompleted(): void
    {
        $client = self::manager();
        self::start($client);
        $number = self::found()->number();

        self::post($client, '/objekte/'.$number.'/bearbeiten/einheiten', ['direction' => 'forward']);

        self::assertResponseIsSuccessful();
        self::assertSame(PropertyStatus::Draft, self::found()->status());
        self::assertStringContainsString(
            'braucht mindestens eine Einheit',
            (string) $client->getResponse()->getContent(),
        );
    }

    /** Jeder Schritt schreibt genau das, was er gefragt hat. */
    public function testEachStepSavesOnItsOwn(): void
    {
        $client = self::manager();
        self::start($client);
        $number = self::found()->number();

        self::post($client, '/objekte/'.$number.'/bearbeiten/anschrift', [
            'street' => 'Musterweg 1',
            'postalCode' => '12345',
            'city' => 'Musterstadt',
            'direction' => 'forward',
        ]);

        self::post($client, '/objekte/'.$number.'/bearbeiten/gebaeude', [
            'yearBuilt' => '1974',
            'livingArea' => '412,5',
            'direction' => 'forward',
        ]);

        $property = self::found();

        self::assertSame('Musterweg 1, 12345 Musterstadt', $property->address()->oneLine());
        self::assertSame(1974, $property->building()->yearBuilt());
        self::assertSame('412.50', $property->building()->livingArea());
    }

    /** Eine unvollstaendige Anschrift kommt zurueck, statt halb zu speichern. */
    public function testAnIncompleteAddressIsRefused(): void
    {
        $client = self::manager();
        self::start($client);
        $number = self::found()->number();

        self::post($client, '/objekte/'.$number.'/bearbeiten/anschrift', [
            'street' => 'Musterweg 1',
            'postalCode' => '',
            'city' => 'Musterstadt',
            'direction' => 'forward',
        ]);

        self::assertResponseIsSuccessful();
        self::assertStringContainsString(
            'Bitte Straße, Postleitzahl und Ort angeben.',
            (string) $client->getResponse()->getContent(),
        );
        self::assertFalse(self::found()->address()->isKnown());
    }

    /**
     * Reine Mietverwaltung kennt keine Miteigentumsanteile — der Schritt
     * entfaellt, statt ein Feld zu zeigen, das nichts bedeutet.
     */
    public function testRentalManagementHasNoSharesStep(): void
    {
        $client = self::manager();
        self::start($client, ManagementMode::Rental);
        $number = self::found()->number();

        $client->request('GET', '/objekte/'.$number.'/bearbeiten/grundbuch');
        $page = (string) $client->getResponse()->getContent();

        self::assertResponseIsSuccessful();
        self::assertStringNotContainsString('/bearbeiten/anteile', $page);
    }

    /**
     * Die Zimmerzahl wird uebernommen und nicht gerundet.
     *
     * Sie lief zuerst durch eine Spalte mit einer Nachkommastelle: „3,25"
     * wurde beim Speichern stillschweigend zu „3,3". Die Zimmerzahl ist aber
     * eine Angabe, die jemand aus einem Vertrag abschreibt — sie darf sich
     * dabei nicht aendern.
     */
    public function testTheRoomCountIsTakenAsGiven(): void
    {
        $client = self::manager();
        self::start($client);
        $number = self::found()->number();

        self::post($client, '/objekte/'.$number.'/einheiten/neu', [
            'label' => 'WE 1, EG links',
            'usage' => 'residential',
        ]);

        self::post($client, '/objekte/'.$number.'/einheiten/1/bearbeiten/details', [
            'area' => '78,4',
            'rooms' => '3,25',
            'parkingSpaces' => '',
            'note' => '',
            'direction' => 'forward',
        ]);

        $units = self::found()->units();

        self::assertCount(1, $units);
        self::assertSame('3.25', $units[0]->measures()->rooms());
        self::assertSame('78.40', $units[0]->measures()->area());
    }

    /**
     * Was keine Zahl ist, wird zurueckgewiesen und nicht als Null gespeichert.
     *
     * Der (int)-Cast machte aus „abc" eine 0 und aus „7 Stueck" eine 7. In der
     * Datenbank stand danach eine Angabe, die niemand gemacht hat — „null
     * Geschosse" sieht hinterher aus wie eine Angabe. Beim Baujahr fiel es
     * nicht auf, weil die Jahreszahl ihre eigene Spanne prueft; bei einer
     * Anzahl ist die Null ein gueltiger Wert.
     */
    public function testSomethingThatIsNotANumberIsRefused(): void
    {
        $client = self::manager();
        self::start($client);
        $number = self::found()->number();

        self::post($client, '/objekte/'.$number.'/bearbeiten/gebaeude', [
            'floors' => 'abc',
            'direction' => 'forward',
        ]);

        self::assertResponseIsSuccessful();
        self::assertNull(self::found()->building()->floors(), 'Nichts wurde gespeichert');
    }

    /**
     * Zu gross fuer die Spalte heisst zurueckgewiesen — nicht Datenbankfehler.
     *
     * Geschosse und Stellplaetze stehen in SMALLINT. Ohne Obergrenze endet
     * eine zu grosse Zahl erst beim Speichern, und zwar als 500er mitten im
     * Ablauf statt als Hinweis am Feld.
     */
    /**
     * Ein leergeraeumtes Pflichtfeld ist kein Serverfehler.
     *
     * Der Tag des Wirtschaftsjahres wurde als Zahl gelesen; ein leeres Feld
     * ist keine, und Symfony beantwortete das mit 400 statt mit dem Hinweis
     * am Feld. Wer versehentlich loescht und weiterklickt, sah eine
     * Fehlerseite und war seinen Schritt los.
     */
    public function testAnEmptyFiscalYearDayIsRefusedAtTheField(): void
    {
        $client = self::manager();
        self::start($client);
        $number = self::found()->number();

        self::post($client, '/objekte/'.$number.'/bearbeiten/abrechnung', [
            'fiscalYearDay' => '',
            'fiscalYearMonth' => '1',
            'direction' => 'forward',
        ]);

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('.ib-field__error', 'Der Hinweis steht am Feld');
    }

    public function testANumberTooBigForTheColumnIsRefused(): void
    {
        $client = self::manager();
        self::start($client);
        $number = self::found()->number();

        self::post($client, '/objekte/'.$number.'/bearbeiten/gebaeude', [
            'floors' => '40000',
            'direction' => 'forward',
        ]);

        self::assertResponseIsSuccessful();
        self::assertNull(self::found()->building()->floors(), 'Nichts wurde gespeichert');
    }

    /** Und eine negative Anzahl gibt es nicht — auch nicht an der Einheit. */
    public function testANegativeCountIsRefused(): void
    {
        $client = self::manager();
        self::start($client);
        $number = self::found()->number();

        self::post($client, '/objekte/'.$number.'/einheiten/neu', [
            'label' => 'WE 1, EG links',
            'usage' => 'residential',
        ]);

        self::post($client, '/objekte/'.$number.'/einheiten/1/bearbeiten/details', [
            'area' => '',
            'rooms' => '',
            'parkingSpaces' => '-3',
            'note' => '',
            'direction' => 'forward',
        ]);

        $units = self::found()->units();

        self::assertResponseIsSuccessful();
        self::assertCount(1, $units);
        self::assertNull($units[0]->measures()->parkingSpaces(), 'Nichts wurde gespeichert');
    }

    protected static function testEmail(): string
    {
        return 'objekte@example.org';
    }

    /**
     * Wirft die Sitzung weg und meldet dasselbe Konto neu an.
     *
     * Genau das, was am naechsten Morgen passiert: kein Zwischenstand, kein
     * Cookie, nichts im Speicher. Ein Ablauf, der seinen Stand in der Sitzung
     * haelt, faende hier ein leeres Formular.
     */
    private static function inANewSession(KernelBrowser $client): void
    {
        $client->restart();

        $users = self::getContainer()->get(UserRepository::class);
        self::assertInstanceOf(UserRepository::class, $users);

        $user = $users->findByEmail(Email::fromString(self::testEmail()));
        self::assertInstanceOf(User::class, $user);

        $client->loginUser($user);
    }

    /** Ein Objekt braucht eine Einheit, bevor es sich abschliessen laesst. */
    private static function givenAUnit(KernelBrowser $client, int $number): void
    {
        self::post($client, '/objekte/'.$number.'/einheiten/neu', [
            'label' => 'WE 1, EG links',
            'usage' => 'residential',
        ]);
    }

    private static function start(KernelBrowser $client, ManagementMode $mode = ManagementMode::Weg): void
    {
        $client->request('POST', '/objekte/neu', [
            '_token' => self::tokenFrom($client, '/objekte/neu'),
            'name' => self::NAME,
            'modes' => [$mode->value],
        ]);
    }

    /**
     * @param array<string, mixed> $fields
     */
    private static function post(KernelBrowser $client, string $url, array $fields): void
    {
        $client->request('POST', $url, [...$fields, '_token' => self::tokenFrom($client, $url)]);
    }

    private static function tokenFrom(KernelBrowser $client, string $url): string
    {
        $crawler = $client->request('GET', $url);
        $field = $crawler->filter('main input[name="_token"]');

        self::assertGreaterThan(0, $field->count(), 'Kein Formular auf '.$url);

        return (string) $field->first()->attr('value');
    }

    private static function manager(): KernelBrowser
    {
        return self::signedInWith([
            PropertyPermissions::VIEW,
            PropertyPermissions::EDIT,
            PropertyPermissions::DELETE,
        ]);
    }

    private static function found(): Property
    {
        self::entityManager()->clear();

        foreach (self::properties()->matching(PropertyFilter::none(), Page::of(1, 100)) as $property) {
            if (self::NAME === $property->name()) {
                return $property;
            }
        }

        self::fail('Das Objekt wurde nicht angelegt.');
    }

    private static function removeProperty(): void
    {
        if (null === self::$kernel) {
            return;
        }

        self::entityManager()->getConnection()->executeStatement(
            'DELETE FROM property WHERE name = ?',
            [self::NAME],
        );
    }

    private static function properties(): PropertyRepository
    {
        $properties = self::getContainer()->get(PropertyRepository::class);
        self::assertInstanceOf(PropertyRepository::class, $properties);

        return $properties;
    }

    private static function entityManager(): EntityManagerInterface
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);

        return $entityManager;
    }
}
