<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Module\Billing\Application\ComposeStatement;
use App\Module\Billing\Application\DraftSelection;
use App\Module\Billing\Domain\Statement;
use App\Module\Billing\Domain\StatementRepository;
use App\Shared\Identity\Uuid;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Was ein Entwurf als Quelle festhaelt.
 *
 * Die Auswahl steht in denselben Zeilen, die die Quelle unloeschbar machen —
 * angehakt heisst eingegangen. Darum ist die Frage, was dort landen darf,
 * keine Formsache: eine Zeile zu viel sperrt einen fremden Jahreswert gegen
 * Loeschen, ohne je auf einem Schreiben zu erscheinen.
 */
final class BillingSourcesTest extends WebTestCase
{
    use BuildsABillableProperty;

    protected function tearDown(): void
    {
        self::removeTheProperty();

        parent::tearDown();
    }

    /**
     * Gespeichert wird nur, was der Lauf auch anbietet.
     *
     * Das Formular ist eine Behauptung. Eine untergeschobene Kennung
     * ignoriert die Berechnung stillschweigend — als Quelle gespeichert
     * wuerde sie den Jahreswert eines fremden Objekts gegen Loeschen sperren,
     * ohne je auf einem Schreiben zu erscheinen. Und zweimal derselbe Haken
     * ist einmal derselbe Haken.
     */
    public function testASourceThatWasNeverOfferedIsNotKept(): void
    {
        $statement = self::aDraftFor(2026);
        $chosen = self::everything($statement);
        $mine = $chosen['costs'][0] ?? '';

        // Erst das Untergeschobene allein — sonst verdeckte der doppelte
        // Haken den Befund, um den es geht.
        self::selection()->keep($statement, [Uuid::v4()], [Uuid::v4()]);
        $kept = self::selection()->of($statement);

        self::assertSame([], $kept['costs'], 'Ein fremder Jahreswert wird nicht festgehalten');
        self::assertSame([], $kept['payments'], 'Eine fremde Zahlung auch nicht');

        // Und dann derselbe Haken zweimal.
        self::selection()->keep($statement, [$mine, $mine], $chosen['payments']);
        $kept = self::selection()->of($statement);

        self::assertSame([$mine], $kept['costs'], 'Zweimal derselbe Haken ist einmal derselbe Haken');

        // Ohne Ruecksicht auf die Reihenfolge: welche Zeilen die Datenbank
        // ohne ORDER BY in welcher Reihenfolge liefert, sagt sie nirgends zu
        // — und fuer die Auswahl zaehlt nur, ob eine Kennung dabei ist. Eine
        // Zusicherung, die mehr verlangt als die Sache hergibt, schlaegt
        // irgendwann fehl, ohne dass sich etwas geaendert haette.
        self::assertEqualsCanonicalizing($chosen['payments'], $kept['payments']);
    }

    private static function aDraftFor(int $year): Statement
    {
        self::bootKernel();
        self::buildTheProperty();

        $statements = self::getContainer()->get(StatementRepository::class);
        self::assertInstanceOf(StatementRepository::class, $statements);
        $statement = new Statement(
            $statements->nextNumber(),
            self::propertyId(),
            self::PROPERTY_NUMBER,
            self::aFiscalYear($year),
        );
        $statements->save($statement);

        return $statement;
    }

    /**
     * @return array{costs: list<string>, payments: list<string>}
     */
    private static function everything(Statement $statement): array
    {
        $compose = self::getContainer()->get(ComposeStatement::class);
        self::assertInstanceOf(ComposeStatement::class, $compose);
        $offered = $compose->offered($statement);

        return [
            'costs' => array_map(static fn (object $cost): string => $cost->costYearId, $offered['costs']),
            'payments' => array_map(static fn (object $paid): string => $paid->paymentId, $offered['payments']),
        ];
    }

    private static function selection(): DraftSelection
    {
        $found = self::getContainer()->get(DraftSelection::class);
        self::assertInstanceOf(DraftSelection::class, $found);

        return $found;
    }
}
