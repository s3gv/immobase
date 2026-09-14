<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Tests\Module\Plugin\Application;

use App\Module\Plugin\Application\IssueToken;
use App\Module\Plugin\Application\KeepPluginsRunning;
use App\Module\Plugin\Application\ReadsManifests;
use App\Module\Plugin\Domain\Manifest\ManifestReader;
use App\Module\Plugin\Domain\Manifest\TableReader;
use App\Module\Plugin\Domain\Plugin;
use App\Module\Plugin\Domain\PluginToken;
use App\Tests\Module\Plugin\Fixture\InMemoryPlugins;
use App\Tests\Module\Plugin\Fixture\ManifestsOnDisk;
use App\Tests\Module\Plugin\Fixture\RecordingNetwork;
use App\Tests\Module\Plugin\Fixture\RecordingProcesses;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Clock\MockClock;

/**
 * Der Aufseher der Plugin-Prozesse.
 *
 * Er entscheidet, was laeuft. Die Zusicherungen: **genau die aktiven Plugins
 * laufen**, jedes mit einem frischen Token, und ein abstuerzendes wird nicht
 * im Sekundentakt neu gestartet.
 */
final class KeepPluginsRunningTest extends TestCase
{
    private InMemoryPlugins $plugins;
    private ManifestsOnDisk $disk;
    private RecordingProcesses $processes;
    private RecordingNetwork $network;
    private MockClock $clock;
    private KeepPluginsRunning $keep;

    protected function setUp(): void
    {
        $this->plugins = new InMemoryPlugins();
        $this->disk = new ManifestsOnDisk();
        $this->processes = new RecordingProcesses();
        $this->network = new RecordingNetwork();
        $this->clock = new MockClock('2026-09-14 09:00:00');

        $this->keep = new KeepPluginsRunning(
            $this->plugins,
            new ReadsManifests($this->disk, new ManifestReader(new TableReader())),
            $this->processes,
            new IssueToken($this->plugins),
            $this->clock,
            'http://localhost',
            $this->network,
        );
    }

    /** Ein aktiviertes Plugin bekommt seinen Prozess — mit einem Token, das gilt. */
    public function testAnActivePluginIsStartedWithAValidToken(): void
    {
        $this->install('reporting', 9100);

        ($this->keep)();

        self::assertSame(['reporting'], $this->processes->started);

        self::assertArrayHasKey('reporting', $this->processes->alive);
        $started = $this->processes->alive['reporting'];
        self::assertSame(9100, $started['port']);
        self::assertArrayHasKey('IMMOBASE_URL', $started['environment']);
        self::assertArrayHasKey('IMMOBASE_TOKEN', $started['environment']);
        self::assertSame('http://localhost', $started['environment']['IMMOBASE_URL']);

        $token = $started['environment']['IMMOBASE_TOKEN'];
        self::assertTrue(PluginToken::looksLikeOne($token));
        self::assertSame(
            'reporting',
            $this->plugins->byTokenHash(PluginToken::seal($token))?->name(),
            'Das mitgegebene Token ist das, das der Core kennt',
        );
    }

    /**
     * Mehr als das bekommt der Prozess nicht.
     *
     * Die Umgebung des Cores traegt DATABASE_URL mit vollem Zugang und
     * APP_SECRET. Was hier nicht ausdruecklich uebergeben wird, darf nicht
     * ankommen — sonst waere die eingeschraenkte Datenbankrolle wertlos.
     */
    public function testThePluginIsGivenNothingButItsUrlAndToken(): void
    {
        $this->install('reporting', 9100);

        ($this->keep)();

        self::assertArrayHasKey('reporting', $this->processes->alive);
        self::assertSame(
            ['IMMOBASE_URL', 'IMMOBASE_TOKEN'],
            array_keys($this->processes->alive['reporting']['environment']),
        );
    }

    /** Ausgesetzt heisst: kein Prozess — und ein laufender wird beendet. */
    public function testASuspendedPluginIsStopped(): void
    {
        $plugin = $this->install('reporting', 9100);
        ($this->keep)();

        $plugin->suspend();
        ($this->keep)();

        self::assertSame(['reporting'], $this->processes->stopped);
        self::assertSame([], $this->processes->running());
    }

    /** Ein Plugin, das nicht mehr auf der Platte liegt, laeuft auch nicht. */
    public function testAPluginMissingFromDiskIsNotStarted(): void
    {
        $this->install('reporting', 9100);
        $this->disk->manifests = [];

        ($this->keep)();

        self::assertSame([], $this->processes->started);
    }

    /**
     * Ein abgestuerztes Plugin kommt wieder — aber nicht sofort.
     *
     * Sonst beschaeftigte eines, das beim Start zusammenbricht, die Maschine
     * im Sekundentakt.
     */
    public function testACrashedPluginIsRestartedAfterAPause(): void
    {
        $this->install('reporting', 9100);
        ($this->keep)();

        $this->processes->crash('reporting');
        ($this->keep)();
        self::assertCount(1, $this->processes->started, 'Nicht gleich wieder');

        $this->clock->sleep(31);
        ($this->keep)();
        self::assertCount(2, $this->processes->started, 'Nach der Pause schon');
    }

    /**
     * Ein Plugin, das nicht startet, haelt die anderen nicht auf — und sein Fehler geht nicht verloren.
     */
    public function testAFailingStartDoesNotHoldUpTheOthers(): void
    {
        $this->install('aaa-kaputt', 9100);
        $this->install('reporting', 9101);
        $this->processes->failing['aaa-kaputt'] = 'Composer endete mit 2';

        try {
            ($this->keep)();
            self::fail('Der Fehler hätte gemeldet werden müssen');
        } catch (RuntimeException $failed) {
            self::assertStringContainsString('Composer endete mit 2', $failed->getMessage());
        }

        self::assertSame(['reporting'], $this->processes->started, 'Das andere läuft trotzdem');
    }

    /**
     * Die Netzsperre steht, bevor ein Plugin startet — und das Internet
     * bekommt nur, wem im zugestimmten Manifest zugestimmt wurde.
     */
    public function testTheNetworkIsRestrictedBeforeAnyStart(): void
    {
        $this->install('reporting', 9100);
        $this->install('wetter', 9101, internet: true);

        ($this->keep)();

        self::assertSame([[9101]], $this->network->restricted);
        self::assertSame(['reporting', 'wetter'], $this->processes->started);
    }

    /** Eine Fassung auf der Platte, die neu das Internet verlangt, bekommt es nicht ohne Zustimmung. */
    public function testTheInternetComesFromTheAgreedManifestNotFromDisk(): void
    {
        $this->install('reporting', 9100);
        $this->disk->manifests['reporting'] = (string) json_encode(['api' => 1, 'name' => 'reporting', 'version' => '1.1.0', 'label' => ['de' => 'Berichte'], 'internet' => true]);

        ($this->keep)();

        self::assertSame([[]], $this->network->restricted);
    }

    /** Ohne Sperre startet nichts — beendet wird trotzdem, was nicht mehr laufen soll. */
    public function testWithoutTheNetworkLockNothingStarts(): void
    {
        $suspended = $this->install('alt', 9101);
        ($this->keep)();
        $suspended->suspend();

        $this->install('reporting', 9100);
        $this->network->failing = true;

        try {
            ($this->keep)();
            self::fail('Der Fehler hätte gemeldet werden müssen');
        } catch (RuntimeException $failed) {
            self::assertStringContainsString('Netzsperre', $failed->getMessage());
        }

        self::assertSame(['alt'], $this->processes->started, 'Nur der Start von vorhin');
        self::assertSame(['alt'], $this->processes->stopped);
    }

    private function install(string $name, int $port, bool $internet = false): Plugin
    {
        $manifest = (string) json_encode([
            'api' => 1,
            'name' => $name,
            'version' => '1.0.0',
            'label' => ['de' => 'Berichte'],
            'internet' => $internet,
        ]);

        $this->disk->manifests[$name] = $manifest;
        $plugin = new Plugin($name, '1.0.0', $manifest, 'kein-token', 'pw', new DateTimeImmutable(), 'Prüfer', $port);
        $this->plugins->save($plugin);

        return $plugin;
    }
}
