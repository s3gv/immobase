<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Module\Billing\Application\DraftSelection;
use App\Module\Billing\Application\ReleaseStatement;
use App\Module\Billing\Domain\BillingPermissions;
use App\Module\Billing\Domain\Statement;
use App\Module\Billing\Domain\StatementRepository;
use App\Module\Finance\Domain\AdvanceKind;
use App\Module\Finance\Domain\ReserveMovementKind;
use App\Shared\Money\Money;
use DateTimeImmutable;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Der Weg vom Knopf zum Archiv.
 *
 * Ein ZIP und keine Einzeldatei: wer abrechnet, verschickt ein Objekt und
 * nicht eine Wohnung.
 */
final class BillingPdfTest extends WebTestCase
{
    use BuildsABillableProperty;
    use ForgetsRateLimits;
    use ReadsPdfArchives;
    use SignsIn;

    protected function tearDown(): void
    {
        self::removeTheProperty();
        self::removeTestUser();

        parent::tearDown();
    }

    public function testTheDownloadIsAZipOfPdfs(): void
    {
        $client = self::signedInWith([BillingPermissions::VIEW, BillingPermissions::EDIT]);
        $statement = self::aReleasedStatement();

        $client->request('GET', '/billing/abrechnungen/'.$statement->id().'/pdf');

        self::assertResponseIsSuccessful();
        self::assertSame('application/zip', $client->getResponse()->headers->get('Content-Type'));

        $bundle = (string) $client->getResponse()->getContent();

        self::assertStringStartsWith('PK', $bundle, 'Ein Archiv beginnt mit PK');
        self::assertGreaterThan(1000, \strlen($bundle));
        self::assertSame(
            \count($statement->documents()),
            self::filesIn($bundle),
            'Jeder Empfänger bekommt seine eigene Datei — keine überschreibt eine andere',
        );
    }

    /**
     * Was auf dem Blatt steht, ist deutsch geschrieben.
     *
     * Der Anteil steht gespeichert als `78.40` da — maschinenlesbar. So
     * gedruckt liest ein deutscher Empfaenger `7840`, und daneben steht im
     * selben Atemzug ein Betrag mit Komma. Der Schluessel muss nachrechenbar
     * sein (staendige Rechtsprechung zu § 259 BGB); zwei Schreibweisen auf
     * einem Blatt sind das Gegenteil davon.
     *
     * Und die Referenz traegt ihr Kuerzel: `HG` oder `NK`, nie nur die Nummer.
     */
    public function testTheLetterWritesNumbersTheWayTheLanguageDoes(): void
    {
        $client = self::signedInWith([BillingPermissions::VIEW, BillingPermissions::EDIT]);
        $statement = self::aReleasedStatement();

        $client->request('GET', '/billing/abrechnungen/'.$statement->id().'/pdf');

        $text = self::textIn((string) $client->getResponse()->getContent());

        self::assertStringContainsString('78,40', $text, 'Der Anteil steht mit Komma da');
        self::assertStringNotContainsString('78.40', $text, '„78.40" liest sich als 7840');
        self::assertStringContainsString('HG-'.self::PROPERTY_NUMBER.'/', $text, 'Die Art steht vor der Nummer');
    }

    /** Aus einem Entwurf entsteht keines. */
    public function testADraftHasNoPdf(): void
    {
        $client = self::signedInWith([BillingPermissions::VIEW, BillingPermissions::EDIT]);
        self::buildTheProperty();

        $statements = self::statements();
        $draft = new Statement($statements->nextNumber(), self::propertyId(), self::PROPERTY_NUMBER, self::aFiscalYear(2026));
        $statements->save($draft);

        $client->request('GET', '/billing/abrechnungen/'.$draft->id().'/pdf');

        self::assertResponseStatusCodeSame(404);
    }

    /** Ohne Recht kein Archiv. */
    public function testItNeedsThePermission(): void
    {
        $client = self::signedInWith([]);
        self::buildTheProperty();

        $statements = self::statements();
        $draft = new Statement($statements->nextNumber(), self::propertyId(), self::PROPERTY_NUMBER, self::aFiscalYear(2026));
        $statements->save($draft);

        $client->request('GET', '/billing/abrechnungen/'.$draft->id().'/pdf');

        self::assertResponseStatusCodeSame(403);
    }

    /**
     * Auf dem Blatt steht, wofuer jede Zahlung war.
     *
     * Am Ersten des Monats ist das Hausgeld faellig — und seit dem Budgetplan
     * kann am selben Tag eine Rate der Sonderumlage daneben stehen. Zwei
     * Zeilen mit demselben Tag und verschiedenen Betraegen sind ohne ihre Art
     * eine Frage an die Verwaltung.
     */
    public function testTheLetterNamesWhatEachPaymentWasFor(): void
    {
        $client = self::signedInWith([BillingPermissions::VIEW, BillingPermissions::EDIT]);
        $statement = self::aReleasedStatement(withALevy: true);

        $client->request('GET', '/billing/abrechnungen/'.$statement->id().'/pdf');
        $text = self::textIn((string) $client->getResponse()->getContent());

        self::assertStringContainsString('Hausgeld', $text, 'Die gewohnte Zahlung');
        self::assertStringContainsString('Sonderumlage', $text, 'Und die aus dem Beschluss');
        self::assertStringContainsString('1.666,67', $text, 'Mit ihrem Betrag');
    }

    /**
     * Ein langer Brief bricht sauber um.
     *
     * FPDF bricht von selbst um, sobald eine Zelle unter den Rand geraet — und
     * zwar mitten im Block: die Beschriftung stand auf der einen Seite, ihr
     * Betrag auf der naechsten, und dazwischen lag ein leeres Blatt. Die
     * Folgeseite traegt jetzt die Referenz, und was zusammengehoert, bleibt
     * beisammen.
     */
    public function testALongLetterBreaksIntoAProperSecondPage(): void
    {
        $client = self::signedInWith([BillingPermissions::VIEW, BillingPermissions::EDIT]);
        $statement = self::aReleasedStatement(withALevy: true, withAReserve: true);

        $client->request('GET', '/billing/abrechnungen/'.$statement->id().'/pdf');
        $bundle = (string) $client->getResponse()->getContent();
        $text = self::textIn($bundle);

        self::assertStringContainsString('Die Erhaltungsrücklage im Abrechnungsjahr', $text);

        // Zwei Seiten sind hier richtig. Dass ein Block nicht zerreisst,
        // steht in SheetTest — hier steht, dass der lange Brief ueberhaupt
        // durchgeht und nicht auf einem dritten, fast leeren Blatt endet.
        self::assertSame([2, 2], self::pagesIn($bundle), 'Zwei Seiten je Hausgeldabrechnung, nicht drei');

        // Und beide Blaetter sagen, das wievielte sie sind: wer zwei Seiten
        // aus dem Kuvert nimmt, soll sehen, ob eine dritte fehlt.
        self::assertStringContainsString('Seite 1 von 2', $text, 'Die Fußzeile des ersten Blattes');
        self::assertStringContainsString('Seite 2 von 2', $text, 'Die des zweiten');
    }

    protected static function testEmail(): string
    {
        return 'archiv@example.org';
    }

    private static function aReleasedStatement(bool $withALevy = false, bool $withAReserve = false): Statement
    {
        self::buildTheProperty();

        if ($withALevy) {
            self::alsoLevied(0, Money::fromCents(166667), '2026-10-01');
        }

        if ($withAReserve) {
            self::alsoMovedTheReserve(ReserveMovementKind::Opening, Money::fromCents(4180000), '2026-01-01');
            self::alsoLevied(0, Money::fromCents(333334), '2026-11-01', AdvanceKind::ReserveLevy);
        }

        $statements = self::statements();
        $statement = new Statement($statements->nextNumber(), self::propertyId(), self::PROPERTY_NUMBER, self::aFiscalYear(2026));
        $statements->save($statement);

        $selection = self::getContainer()->get(DraftSelection::class);
        self::assertInstanceOf(DraftSelection::class, $selection);
        $release = self::getContainer()->get(ReleaseStatement::class);
        self::assertInstanceOf(ReleaseStatement::class, $release);

        $selection->keep($statement, self::allCostYears(), self::allPayments());
        $release->release($statement, new DateTimeImmutable('2027-03-01'));

        return $statement;
    }

    /** @return list<string> */
    private static function allCostYears(): array
    {
        $ids = [];

        foreach (self::items()->forProperty(self::propertyId()) as $item) {
            foreach ($item->years()->all() as $year) {
                $ids[] = $year->id();
            }
        }

        return $ids;
    }

    /** @return list<string> */
    private static function allPayments(): array
    {
        $units = [];

        foreach (self::unitIds() as $id) {
            $units[] = $id;
        }

        return array_map(
            static fn (object $payment): string => $payment->id(),
            self::paid()->forYear($units, 2026),
        );
    }

    private static function statements(): StatementRepository
    {
        $found = self::getContainer()->get(StatementRepository::class);
        self::assertInstanceOf(StatementRepository::class, $found);

        return $found;
    }
}
