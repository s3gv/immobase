<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Module\Billing\Application\ComposeStatement;
use App\Module\Billing\Domain\MissingFigure;
use App\Module\Billing\Domain\Proposal;
use App\Module\Billing\Domain\ProposedDocument;
use App\Module\Billing\Domain\Statement;
use App\Module\Billing\Domain\StatementKind;
use App\Module\Billing\Domain\StatementRepository;
use App\Shared\Money\Money;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Der Verteilerschluessel nach Personen — ohne Mietvertrag.
 *
 * Die Personenzahl hing am Mietverhaeltnis. Eine selbstbewohnte
 * Eigentumswohnung hat keins, eine leerstehende auch nicht — und damit lief
 * jeder Personenschluessel fuer sie ins Leere und sperrte die Freigabe. Das
 * trifft nicht den Randfall: in einer WEG wohnt ein guter Teil der
 * Eigentuemer selbst, und Muellabfuhr, Wasser und Hausreinigung werden
 * haeufig nach Personen verteilt.
 *
 * Die Regel ist tagesgenau und kennt keinen Zweifel: **laeuft an einem Tag
 * ein Mietverhaeltnis, zaehlt dessen Zahl, sonst die der Einheit.** Leerstand
 * ist derselbe Fall wie Selbstnutzung — es gibt keinen Mietvertrag —, und
 * damit traegt ihn der Eigentuemer und nicht die Nachbarn.
 */
final class BillingPersonKeyTest extends WebTestCase
{
    use BuildsABillableProperty;

    /** Der Betrag, der nach Personen verteilt wird. */
    private const int RUBBISH = 60000;

    protected function tearDown(): void
    {
        self::removeTheProperty();

        parent::tearDown();
    }

    /**
     * Zwei selbstbewohnte Einheiten — und die Summe geht auf.
     *
     * Drei Personen gegen eins: 45.000 zu 15.000 Cent. Kein Mietverhaeltnis
     * weit und breit, und trotzdem faellt nichts aus.
     */
    public function testTwoOwnerOccupiedFlatsCanBeBilledByPerson(): void
    {
        $documents = self::documentsWithPersons([3, 1]);

        self::assertSame(
            [45000, 15000],
            [self::rubbishOf($documents, 1)->cents(), self::rubbishOf($documents, 2)->cents()],
        );
    }

    /** Und zusammen ist es genau der Betrag, der hereinkam. */
    public function testTheSharesAddUpToTheWholeAmount(): void
    {
        $documents = self::documentsWithPersons([3, 1]);

        self::assertTrue(
            self::rubbishOf($documents, 1)->plus(self::rubbishOf($documents, 2))->equals(Money::fromCents(self::RUBBISH)),
        );
    }

    /**
     * Leerstand traegt der Eigentuemer — nicht die Nachbarn.
     *
     * Eine leerstehende Wohnung ist weder vermietet noch selbst bewohnt, und
     * sie zaehlt trotzdem mit der Zahl, die der Eigentuemer zu vertreten hat.
     * Stuende sie mit null da, verteilte sich ihr Anteil still auf die
     * anderen Einheiten: der Leerstand des einen waere die Rechnung des
     * anderen.
     */
    public function testAVacantFlatIsBorneByItsOwner(): void
    {
        $vacant = self::documentsWithPersons([1, 1]);
        $shared = self::rubbishOf($vacant, 1);

        self::assertSame(30000, $shared->cents(), 'Zwei gleiche Zahlen, die Hälfte');
        self::assertTrue(
            $shared->plus(self::rubbishOf($vacant, 2))->equals(Money::fromCents(self::RUBBISH)),
            'Der Leerstand fällt niemandem zu, der ihn nicht hält',
        );
    }

    /**
     * Und ohne Angabe bleibt es eine fehlende Angabe.
     *
     * Eine Null verteilte den Anteil der Wohnung still auf die Nachbarn. Die
     * Abrechnung sagt stattdessen, was fehlt, und laesst sich nicht
     * freigeben.
     */
    public function testWithoutANumberTheFigureIsReportedMissing(): void
    {
        self::buildTheProperty();
        self::alsoCostsByPerson(Money::fromCents(self::RUBBISH));

        $proposal = self::proposal();

        self::assertNotSame([], $proposal->missing, 'Was fehlt, wird nicht zu null');
        self::assertSame(
            'billing.missing.persons',
            ($proposal->missing[0] ?? null) instanceof MissingFigure ? $proposal->missing[0]->whatKey : '',
        );
    }

    /**
     * Ein Lauf ueber das Objekt, mit einer Kostenart nach Personen.
     *
     * @param list<int> $people Personenzahl je Einheit, in ihrer Reihenfolge
     *
     * @return list<ProposedDocument>
     */
    private static function documentsWithPersons(array $people): array
    {
        self::buildTheProperty();
        self::alsoCostsByPerson(Money::fromCents(self::RUBBISH));

        foreach ($people as $at => $count) {
            self::housesItself($at, $count);
        }

        $proposal = self::proposal();

        self::assertSame([], $proposal->missing, 'Mit Personenzahl fehlt nichts');

        return $proposal->documents;
    }

    private static function proposal(): Proposal
    {
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
        );
    }

    /**
     * Der Anteil einer Einheit an der Kostenart nach Personen.
     *
     * @param list<ProposedDocument> $documents
     */
    private static function rubbishOf(array $documents, int $unitNumber): Money
    {
        foreach ($documents as $document) {
            if (StatementKind::HouseMoney !== $document->kind || $document->unitNumber !== $unitNumber) {
                continue;
            }

            foreach ($document->lines as $line) {
                if ('Prüfmüll' === $line->costKind) {
                    return $line->amount;
                }
            }
        }

        self::fail('Einheit '.$unitNumber.' hat keine Zeile für die Kostenart nach Personen.');
    }
}
