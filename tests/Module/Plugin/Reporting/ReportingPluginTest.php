<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Tests\Module\Plugin\Reporting;

use App\Tests\Module\Plugin\Reporting\Fixture\ChangingCore;
use App\Tests\Module\Plugin\Reporting\Fixture\WaitingStorage;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Reporting\Decimal;
use Reporting\Mirror;
use Reporting\Signature;
use Symfony\Component\Clock\MockClock;

require_once \dirname(__DIR__, 4).'/plugins/reporting/src/Decimal.php';
require_once \dirname(__DIR__, 4).'/plugins/reporting/src/Signature.php';
require_once \dirname(__DIR__, 4).'/plugins/reporting/src/Source.php';
require_once \dirname(__DIR__, 4).'/plugins/reporting/src/MirrorStorage.php';
require_once \dirname(__DIR__, 4).'/plugins/reporting/src/Mirror.php';

/**
 * Zusagen des Referenz-Plugins, die man ihm nicht ansieht.
 *
 * Das Plugin hat keinen eigenen Testlauf; seine Klassen werden hier einzeln
 * geladen — nicht ueber einen Autoloader, den es mit dem Core teilen wuerde.
 */
final class ReportingPluginTest extends TestCase
{
    /**
     * Betraege ohne Gleitkomma.
     *
     * Die letzte Zeile ist die, an der ein float scheitert: so viele Stellen
     * traegt keine Gleitkommazahl, und die Rundung liefe an der falschen
     * Stelle.
     */
    #[DataProvider('amounts')]
    public function testAmountsAreWrittenDigitByDigit(string $value, int $places, string $expected): void
    {
        self::assertSame($expected, Decimal::format($value, $places));
    }

    /**
     * @return iterable<string, array{string, int, string}>
     */
    public static function amounts(): iterable
    {
        yield 'Tausender und Rundung' => ['1234.5678', 2, '1.234,57'];
        yield 'aufgerundet' => ['0.005', 2, '0,01'];
        yield 'keine negative Null' => ['-0.004', 2, '0,00'];
        yield 'NUMERIC aus der Datenbank' => ['41970.000000', 2, '41.970,00'];
        yield 'negativ gerundet' => ['-7.25', 1, '-7,3'];
        yield 'Uebertrag durch alle Stellen' => ['999999999999999999.995', 2, '1.000.000.000.000.000.000,00'];

        // 1.005 liegt als float knapp unter der Grenze — die Rundung muss an
        // der Ziffer entscheiden, nicht am Naeherungswert. Und so viele
        // Stellen wie in der letzten Zeile traegt ein float gar nicht: dort
        // stuende 568,00 statt 567,89.
        yield 'kaufmaennisch, nicht binaer' => ['1.005', 2, '1,01'];
        yield 'mehr Stellen als ein float' => ['12345678901234567.89', 2, '12.345.678.901.234.567,89'];
    }

    public function testTheSignOfAnAmountNeedsNoFloatEither(): void
    {
        self::assertFalse(Decimal::isPositive('0.000000'));
        self::assertTrue(Decimal::isNegative('-0.01'));
        self::assertTrue(Decimal::isPositive('0.000001'));
    }

    /**
     * Ein Webhook ohne Unterschrift des Cores wird nicht verarbeitet.
     *
     * Unterschrieben wird mit dem Abdruck des Tokens ueber den Rumpf, so wie
     * er ankam — ein veraenderter Rumpf oder ein anderes Token fallen durch.
     */
    public function testOnlyTheCoresSignatureCounts(): void
    {
        $token = 'ib_'.str_repeat('a', 64);
        $body = '{"event":"costs.updated","resource":"costs","id":"1","at":"2026-09-14T09:00:00+00:00"}';
        $signed = 'sha256='.hash_hmac('sha256', $body, hash('sha256', $token));

        self::assertTrue(Signature::isValid($body, $signed, $token));
        self::assertFalse(Signature::isValid($body, '', $token), 'Ohne Unterschrift');
        self::assertFalse(Signature::isValid($body.' ', $signed, $token), 'Mit verändertem Rumpf');
        self::assertFalse(Signature::isValid($body, $signed, 'ib_'.str_repeat('b', 64)), 'Mit fremdem Token');
    }

    /**
     * Eine aeltere Spiegelung ueberschreibt nie eine neuere.
     *
     * Der Fall: ein Seitenaufruf spiegelt, waehrend er holt, aendert sich
     * etwas im Core, und der Webhook dazu kommt an. Holte der Webhook
     * sofort und schriebe als Erster, schriebe der Seitenaufruf danach
     * seinen alten Stand darueber — und der gaelte noch eine Viertelstunde
     * als frisch.
     */
    public function testAnOlderRefreshNeverOverwritesANewerOne(): void
    {
        $clock = new MockClock('2026-09-14 09:00:00');
        $core = new ChangingCore();
        $storage = new WaitingStorage();
        $mirror = new Mirror($core, $storage, $clock->now(...));

        $core->whileFetching = static function () use ($core, $clock, $mirror): void {
            $clock->sleep(5);
            $core->propertyName = 'neu';
            $mirror->refreshUnlessCovered($clock->now());
            $clock->sleep(1);
        };

        $mirror->refreshUnlessCovered($clock->now()->modify(Mirror::FRESH));

        self::assertSame(2, $core->fetches, 'Der Webhook hat nach dem Seitenaufruf noch einmal geholt');
        self::assertSame('neu', $storage->tables['property'][0]['name'] ?? null, 'Und sein Stand ist der, der bleibt');
    }

    /**
     * Was eine Spiegelung schon enthaelt, holt keine zweite.
     *
     * Hundert Meldungen einer Massenaenderung sollen nicht hundertmal den
     * ganzen Bestand holen.
     */
    public function testAChangeAlreadyInTheMirrorIsNotFetchedAgain(): void
    {
        $clock = new MockClock('2026-09-14 09:00:00');
        $core = new ChangingCore();
        $storage = new WaitingStorage();
        $mirror = new Mirror($core, $storage, $clock->now(...));

        $changedAt = $clock->now();
        $clock->sleep(10);

        self::assertTrue($mirror->refreshUnlessCovered($changedAt), 'Die erste Meldung spiegelt');
        self::assertFalse($mirror->refreshUnlessCovered($changedAt), 'Die zweite nicht mehr');
        self::assertSame(1, $core->fetches);
    }

    /**
     * Ein Abruf kurz nach der Aenderung deckt sie noch nicht.
     *
     * Geschrieben ist nicht festgeschrieben — und der Zeitpunkt der Meldung
     * ist auf die Sekunde gerundet.
     */
    public function testARefreshRightAfterTheChangeDoesNotCoverIt(): void
    {
        $clock = new MockClock('2026-09-14 09:00:00');
        $core = new ChangingCore();
        $storage = new WaitingStorage();
        $storage->lastSync = new DateTimeImmutable('2026-09-14 09:00:01');
        $mirror = new Mirror($core, $storage, $clock->now(...));

        self::assertTrue($mirror->refreshUnlessCovered(new DateTimeImmutable('2026-09-14 09:00:00')));
    }
}
