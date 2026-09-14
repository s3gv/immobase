<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Tests\Shared\Time;

use App\Shared\Time\Windows;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

/**
 * Aus Anfangsdaten werden Zeitfenster.
 *
 * Die Rechenregel unter jeder Staffel: eine Stufe gilt bis zum Tag vor der
 * naechsten. Fuer die Abrechnung ist das keine Nebensache — an ihr haengt,
 * welcher Betrag wie viele Tage lang galt.
 */
final class WindowsTest extends TestCase
{
    private const string YEAR_BEGINS = '2026-01-01';
    private const string YEAR_ENDS = '2026-12-31';

    /** Eine einzige Stufe fuellt den ganzen Zeitraum. */
    public function testOneStepFillsThePeriod(): void
    {
        $windows = self::within(['2026-01-01']);

        $only = self::single($windows);

        self::assertSame('2026-01-01', $only['from']->format('Y-m-d'));
        self::assertSame('2026-12-31', $only['to']->format('Y-m-d'));
    }

    /** Die erste Stufe endet am Tag vor der zweiten — nicht an ihrem Tag. */
    public function testAStepEndsTheDayBeforeTheNext(): void
    {
        $windows = self::within(['2026-01-01', '2026-07-01']);

        self::assertCount(2, $windows);
        self::assertSame(
            ['2026-01-01', '2026-06-30', '2026-07-01', '2026-12-31'],
            self::edges($windows),
        );
    }

    /**
     * Eine Stufe von vorletztem Jahr gilt am ersten Tag weiter.
     *
     * Der haeufigste Fall ueberhaupt: die Miete wurde 2023 zuletzt geaendert
     * und gilt 2026 unveraendert. Ihr Fenster faengt am 1. Januar an und
     * nicht 2023.
     */
    public function testAStepFromBeforeThePeriodStartsAtItsFirstDay(): void
    {
        $windows = self::within(['2023-04-01']);

        self::assertSame('2026-01-01', self::single($windows)['from']->format('Y-m-d'));
    }

    /** Was nach dem Zeitraum beginnt, kommt nicht vor. */
    public function testAStepAfterThePeriodIsLeftOut(): void
    {
        $windows = self::within(['2026-01-01', '2027-01-01']);

        self::assertSame('2026-12-31', self::single($windows)['to']->format('Y-m-d'));
    }

    /**
     * Von zwei Stufen vor dem Zeitraum zaehlt nur die spaetere.
     *
     * Sonst stuenden zwei Fenster mit demselben Anfangstag da, und das
     * frueheste haette null Tage.
     */
    public function testOnlyTheLastStepBeforeThePeriodSurvives(): void
    {
        $windows = self::within(['2022-01-01', '2024-06-01']);

        $only = self::single($windows);

        self::assertSame('2026-01-01', $only['from']->format('Y-m-d'));
        self::assertSame('2026-12-31', $only['to']->format('Y-m-d'));
    }

    /** Die Fenster decken den Zeitraum lückenlos und ohne Überschneidung. */
    public function testTheWindowsCoverThePeriodExactly(): void
    {
        $windows = self::within(['2025-11-01', '2026-03-15', '2026-03-16', '2026-09-01']);

        $days = 0;
        $previous = null;

        foreach ($windows as $window) {
            $days += (int) $window['from']->diff($window['to'])->days + 1;

            if (null !== $previous) {
                self::assertSame(
                    $window['from']->format('Y-m-d'),
                    $previous->modify('+1 day')->format('Y-m-d'),
                    'Kein Tag doppelt und keiner ausgelassen',
                );
            }

            $previous = $window['to'];
        }

        self::assertSame(365, $days, 'Das Jahr geht auf');
        $edges = self::edges($windows);
        self::assertSame('2026-01-01', reset($edges));
        self::assertSame('2026-12-31', end($edges));
    }

    /** Ohne Stufen gibt es kein Fenster — und keinen Fehler. */
    public function testNoStepsGiveNoWindows(): void
    {
        self::assertSame([], self::within([]));
    }

    /**
     * @param list<array{from: DateTimeImmutable, to: DateTimeImmutable, step: DateTimeImmutable}> $windows
     *
     * @return array{from: DateTimeImmutable, to: DateTimeImmutable, step: DateTimeImmutable}
     */
    private static function single(array $windows): array
    {
        self::assertCount(1, $windows);

        return $windows[0];
    }

    /**
     * Anfang und Ende jedes Fensters, der Reihe nach.
     *
     * @param list<array{from: DateTimeImmutable, to: DateTimeImmutable, step: DateTimeImmutable}> $windows
     *
     * @return list<string>
     */
    private static function edges(array $windows): array
    {
        $edges = [];

        foreach ($windows as $window) {
            $edges[] = $window['from']->format('Y-m-d');
            $edges[] = $window['to']->format('Y-m-d');
        }

        return $edges;
    }

    /**
     * @param list<string> $starts
     *
     * @return list<array{from: DateTimeImmutable, to: DateTimeImmutable, step: DateTimeImmutable}>
     */
    private static function within(array $starts): array
    {
        return Windows::within(
            array_map(static fn (string $day): DateTimeImmutable => new DateTimeImmutable($day), $starts),
            static fn (DateTimeImmutable $day): DateTimeImmutable => $day,
            new DateTimeImmutable(self::YEAR_BEGINS),
            new DateTimeImmutable(self::YEAR_ENDS),
        );
    }
}
