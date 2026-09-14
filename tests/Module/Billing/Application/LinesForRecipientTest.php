<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Tests\Module\Billing\Application;

use App\Module\Billing\Application\LinesForRecipient;
use App\Module\Billing\Domain\Distribution;
use App\Module\Billing\Domain\ProposedLine;
use App\Module\Billing\Domain\StatementKind;
use App\Module\Finance\Contract\CostRecord;
use App\Shared\Money\Money;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

/**
 * Welche Zeilen ein Empfaenger bekommt — und mit welchem Zeitanteil.
 *
 * Zwei Regeln, die einzeln harmlos aussehen und zusammen darueber
 * entscheiden, ob eine Abrechnung stimmt: der Mieter traegt nur
 * Umlagefaehiges, und er traegt nur seine Tage.
 */
final class LinesForRecipientTest extends TestCase
{
    private const string YEAR_FROM = '2026-01-01';
    private const string YEAR_TO = '2026-12-31';

    /** Ein volles Jahr bekommt den vollen Anteil. */
    public function testAFullYearGetsTheFullShare(): void
    {
        $lines = self::of(self::aCost(), self::YEAR_FROM, self::YEAR_TO);

        self::assertCount(1, $lines);
        self::assertSame(68202, $lines[0]->amount->cents());
    }

    /**
     * Ein Quartal traegt sein Quartal.
     *
     * April bis Juni sind 91 Tage: 682,02 Euro davon sind 170,04 Euro — und
     * nicht 682,02. Wer im April einzieht, traegt nicht die Grundsteuer des
     * ganzen Jahres.
     */
    public function testAQuarterOnlyCarriesItsDays(): void
    {
        $lines = self::of(self::aCost(), '2026-04-01', '2026-06-30');

        self::assertCount(1, $lines);
        self::assertSame(17004, $lines[0]->amount->cents());
        self::assertSame(91, $lines[0]->distribution->daysOf());
        self::assertSame(365, $lines[0]->distribution->daysTotal());
    }

    /**
     * Was sich nicht tagesgenau teilt, trifft den am Stichtag.
     *
     * Die Kaminkehrergebuehr faellt einmal an, und sie faellt bei dem an, der
     * am letzten Tag des Wirtschaftsjahres dort wohnt. Halbieren ginge nicht.
     */
    public function testWithoutDailySplitTheKeyDateDecides(): void
    {
        $cost = self::aCost(splitsByDay: false);

        self::assertCount(0, self::of($cost, '2026-04-01', '2026-06-30'), 'Wer vorher auszog, zahlt nicht');
        self::assertCount(1, self::of($cost, '2026-07-01', '2026-12-31'), 'Wer am Jahresende da war, zahlt');
    }

    /** Fuer den Mieter zaehlt nur, was umlagefaehig ist. */
    public function testATenantOnlyCarriesApportionableCosts(): void
    {
        $cost = self::aCost(apportionable: false);

        self::assertCount(0, self::of($cost, self::YEAR_FROM, self::YEAR_TO));
        self::assertCount(
            1,
            self::of($cost, self::YEAR_FROM, self::YEAR_TO, StatementKind::HouseMoney),
            'Der Eigentümer bekommt auch das Nicht-Umlagefähige',
        );
    }

    /**
     * Zwei Zeitraeume ergeben zusammen wieder den ganzen Anteil.
     *
     * Der Fall, an dem sich zeigt, ob zeitanteilig gerechnet oder gerundet
     * wird: bei einem Mieterwechsel darf kein Cent verschwinden und keiner
     * doppelt auftauchen.
     */
    public function testTwoPeriodsAddUpToTheWholeShare(): void
    {
        $cost = self::aCost();
        $first = self::of($cost, '2026-01-01', '2026-06-30');
        $second = self::of($cost, '2026-07-01', '2026-12-31');

        self::assertCount(1, $first);
        self::assertCount(1, $second);
        self::assertSame(
            68202,
            $first[0]->amount->cents() + $second[0]->amount->cents(),
            'Kein Cent verloren, keiner erfunden',
        );
    }

    /**
     * Die Steuer darin teilt sich wie der Betrag — und geht ebenso auf.
     *
     * Ein Gewerbemieter, der im Juli auszieht, traegt die Haelfte der
     * enthaltenen Steuer, nicht die ganze und nicht keine. Sonst stimmte
     * seine Nettosumme, und die des Nachfolgers nicht.
     */
    public function testTheIncludedTaxSplitsLikeTheAmount(): void
    {
        $cost = self::aCost();
        $first = self::of($cost, '2026-01-01', '2026-06-30', tax: 10889);
        $second = self::of($cost, '2026-07-01', '2026-12-31', tax: 10889);

        self::assertCount(1, $first);
        self::assertCount(1, $second);
        self::assertSame(5399, $first[0]->inputTax->cents(), '181 von 365 Tagen, abgeschnitten wie der Betrag');
        self::assertSame(10889, $first[0]->inputTax->cents() + $second[0]->inputTax->cents(), 'Zusammen genau das Ganze');
        self::assertSame(
            $first[0]->amount->cents() - $first[0]->inputTax->cents(),
            $first[0]->net()->cents(),
        );
    }

    /**
     * @return list<ProposedLine>
     */
    private static function of(
        CostRecord $cost,
        string $from,
        string $to,
        StatementKind $kind = StatementKind::OperatingCosts,
        int $tax = 0,
    ): array {
        return LinesForRecipient::of(
            $kind,
            [$cost],
            [$cost->costYearId => new ProposedLine(
                $cost->kindLabel,
                Distribution::by('Wohnfläche', 'billing.key.area', '78.40', '142.60'),
                $cost->total,
                Money::fromCents(68202),
                Money::fromCents($tax * 2),
                Money::fromCents($tax),
            )],
            new DateTimeImmutable($from),
            new DateTimeImmutable($to),
            new DateTimeImmutable(self::YEAR_FROM),
            new DateTimeImmutable(self::YEAR_TO),
        );
    }

    private static function aCost(bool $splitsByDay = true, bool $apportionable = true): CostRecord
    {
        return new CostRecord(
            costYearId: 'jahr-1',
            itemNumber: 90001,
            costKindId: 'art-1',
            kindLabel: 'Grundsteuer',
            apportionable: $apportionable,
            splitsByDay: $splitsByDay,
            keyId: 'schluessel-1',
            keyLabel: 'Wohnfläche',
            keyKind: 'area',
            total: Money::fromCents(124050),
            inputTax: Money::zero(),
            measure: null,
            perUnit: [],
        );
    }
}
