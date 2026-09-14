<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Tests\Module\Plugin\Infrastructure;

use App\Module\Plugin\Infrastructure\FrankenPhpProcesses;
use App\Module\Plugin\Infrastructure\PluginDependencies;
use App\Module\Plugin\Infrastructure\PluginRunner;
use App\Module\Plugin\Infrastructure\ProcessEnvironment;
use App\Module\Plugin\Infrastructure\ProcessIdentity;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Die Plugin-Prozesse — was sie sehen und wen sie treffen.
 *
 * Drei Zusicherungen, und alle drei sind Grenzen: ein Plugin-Prozess erbt
 * keine Geheimnisse des Cores, auch nicht beim Nachinstallieren seiner
 * Abhaengigkeiten — und eine alte Kennung trifft nie einen fremden Prozess.
 */
final class FrankenPhpProcessesTest extends TestCase
{
    private string $state;
    private string $proc;

    protected function setUp(): void
    {
        $this->state = sys_get_temp_dir().'/immobase-plugins-'.bin2hex(random_bytes(4));
        $this->proc = $this->state.'-proc';
        mkdir($this->state, 0o775, true);
        mkdir($this->proc, 0o775, true);
    }

    protected function tearDown(): void
    {
        putenv('DATABASE_URL');
        putenv('APP_SECRET');
        exec('rm -rf '.escapeshellarg($this->state).' '.escapeshellarg($this->proc)
            .' '.escapeshellarg($this->state.'-plugins').' '.escapeshellarg($this->state.'-bin'));
    }

    /**
     * Die Umgebung des Cores bleibt zurueck.
     *
     * Er laeuft mit DATABASE_URL, APP_SECRET und dem Schluessel der Anhaenge.
     * Ein Plugin, das die erbte, haette den Schluessel zum ganzen Haus.
     */
    public function testTheCoresSecretsStayBehind(): void
    {
        putenv('DATABASE_URL=postgresql://immobase:geheim@database/immobase');
        putenv('APP_SECRET=geheim');

        $environment = ProcessEnvironment::only(['IMMOBASE_TOKEN' => 'ib_x']);

        self::assertArrayNotHasKey('DATABASE_URL', $environment);
        self::assertArrayNotHasKey('APP_SECRET', $environment);
        self::assertArrayHasKey('IMMOBASE_TOKEN', $environment);
        self::assertSame([], array_diff(array_keys($environment), ['PATH', 'IMMOBASE_TOKEN']));
    }

    /**
     * Caddy legt seinen Zustand nicht ins Plugin.
     *
     * Ohne HOME und XDG-Verzeichnisse schreibt Caddy nach `./caddy` — und das
     * Arbeitsverzeichnis ist das Plugin. Dort landeten Kennungen, die in kein
     * Repository gehoeren.
     */
    public function testCaddyKeepsItsStateOutOfThePluginDirectory(): void
    {
        mkdir($this->state.'-plugins/reporting/public', 0o775, true);
        $binary = $this->fakeBinary('frankenphp', 'env > '.escapeshellarg($this->state.'/seen.env'));
        $processes = new FrankenPhpProcesses($this->state.'-plugins', $this->state, new ProcessIdentity($this->proc), new PluginDependencies($this->state), '/app/docker/frankenphp/plugin.Caddyfile', new PluginRunner(null), $binary);

        $processes->start('reporting', 18_123, ['IMMOBASE_TOKEN' => 'ib_x']);
        $seen = $this->waitFor($this->state.'/seen.env');
        $processes->stop('reporting');

        self::assertStringContainsString('HOME='.$this->state.'/reporting.caddy', $seen);
        self::assertStringContainsString('XDG_DATA_HOME='.$this->state.'/reporting.caddy', $seen);
        self::assertStringContainsString('XDG_CONFIG_HOME='.$this->state.'/reporting.caddy', $seen);
        self::assertStringContainsString('IMMOBASE_TOKEN=ib_x', $seen);
        self::assertStringContainsString('IMMOBASE_PLUGIN_PORT=18123', $seen);
        self::assertStringContainsString('IMMOBASE_PLUGIN_ROOT='.$this->state.'-plugins/reporting/public', $seen);
    }

    /**
     * Auch beim Nachinstallieren nicht — und ohne Code des Plugins auszufuehren.
     *
     * Composer fuehrt sonst Scripts aus der composer.json des Plugins aus;
     * mit der Umgebung des Cores kaeme das Plugin genau dabei an sie heran.
     */
    public function testInstallingDependenciesRunsNoPluginCodeAndSeesNoSecrets(): void
    {
        putenv('DATABASE_URL=postgresql://immobase:geheim@database/immobase');

        $dependencies = new PluginDependencies($this->state);
        $command = $dependencies->command('/app/plugins/reporting');
        $environment = $dependencies->environment();

        self::assertContains('--no-scripts', $command);
        self::assertContains('--no-plugins', $command);
        self::assertArrayNotHasKey('DATABASE_URL', $environment);
        self::assertSame([], array_diff(array_keys($environment), ['PATH', 'COMPOSER_HOME']));
    }

    /**
     * Eine wiederverwendete Kennung trifft keinen fremden Prozess.
     *
     * Die PID-Datei zeigt auf einen Prozess, der laeuft — aber nicht mit dem
     * Kommando, mit dem das Plugin gestartet wurde. Er gilt nicht als
     * laufendes Plugin, und Aussetzen beendet ihn nicht: es koennte der
     * Webserver sein.
     */
    public function testAReusedPidIsNeitherCountedNorKilled(): void
    {
        $stranger = proc_open(['sleep', '30'], [], $pipes);
        self::assertIsResource($stranger);
        $pid = proc_get_status($stranger)['pid'];

        try {
            $this->recordPid('reporting', $pid, ['frankenphp', 'php-server', '--root', '/app/plugins/reporting/public']);
            $this->fakeCommandLine($pid, ['sleep', '30']);

            $processes = $this->processes();

            self::assertSame([], $processes->running(), 'Ein fremder Prozess ist kein laufendes Plugin');

            $this->recordPid('reporting', $pid, ['frankenphp', 'php-server', '--root', '/app/plugins/reporting/public']);
            $processes->stop('reporting');

            self::assertTrue(proc_get_status($stranger)['running'], 'Und er lebt noch');
        } finally {
            proc_terminate($stranger);
            proc_close($stranger);
        }
    }

    /** Der eigene Prozess dagegen wird erkannt. */
    public function testOurOwnProcessIsRecognised(): void
    {
        $command = ['frankenphp', 'php-server', '--root', '/app/plugins/reporting/public'];
        $this->recordPid('reporting', 4242, $command);
        $this->fakeCommandLine(4242, $command);

        self::assertSame(['reporting'], $this->processes()->running());
    }

    /**
     * Scheitert Composer, wird das gesagt — mit dem, was Composer dazu schrieb.
     *
     * Sonst startete ein Server ohne Autoloader, stuerbe sofort, und alle
     * dreissig Sekunden sahe es wieder genauso aus.
     */
    public function testAFailingInstallSaysWhy(): void
    {
        $plugin = $this->pluginNeedingDependencies();
        $composer = $this->fakeComposer(<<<'SH'
            cat >&2 <<'OUT'
            Your requirements could not be resolved to an installable set of packages.

              Problem 1
                - Root composer.json requires acme/nirgends, it could not be found in any version.

            Potential causes:
             - A typo in the package name
            OUT
            exit 2
            SH);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/reporting.*2.*requires acme\/nirgends(?!.*Potential causes)/');

        (new PluginDependencies($this->state, $composer))->installIn($plugin);
    }

    /** Auch ein Composer, der „gelingt", aber keinen Autoloader hinterlaesst, ist gescheitert. */
    public function testAnInstallWithoutAutoloaderIsAFailureToo(): void
    {
        $plugin = $this->pluginNeedingDependencies();
        $composer = $this->fakeComposer("echo 'Nothing to install'\nexit 0");

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/ohne vendor\/autoload\.php.*Nothing to install/');

        (new PluginDependencies($this->state, $composer))->installIn($plugin);
    }

    /** Und wenn es gelingt, geht es still weiter. */
    public function testASuccessfulInstallIsQuiet(): void
    {
        $plugin = $this->pluginNeedingDependencies();
        $composer = $this->fakeComposer('mkdir -p vendor && touch vendor/autoload.php');

        (new PluginDependencies($this->state, $composer))->installIn($plugin);

        self::assertFileExists($plugin.'/vendor/autoload.php');
    }

    private function pluginNeedingDependencies(): string
    {
        $plugin = $this->state.'-plugins/reporting';
        mkdir($plugin, 0o775, true);
        file_put_contents($plugin.'/composer.json', '{}');

        return $plugin;
    }

    private function fakeComposer(string $body): string
    {
        return $this->fakeBinary('composer', $body);
    }

    private function fakeBinary(string $name, string $body): string
    {
        $binary = $this->state.'-bin/'.$name;

        if (!is_dir(\dirname($binary))) {
            mkdir(\dirname($binary), 0o775, true);
        }

        file_put_contents($binary, "#!/bin/sh\n".$body."\n");
        chmod($binary, 0o755);

        return $binary;
    }

    /**
     * Wartet, bis in der Datei etwas steht — nicht nur, bis es sie gibt.
     *
     * Die Shell legt die Datei fuer `env > datei` an, bevor `env` schreibt.
     * Wer nur auf die Datei wartete, las gelegentlich eine leere, und der Test
     * scheiterte ab und zu an nichts.
     */
    private function waitFor(string $file): string
    {
        for ($attempt = 0; $attempt < 100; ++$attempt) {
            clearstatcache(true, $file);

            if (is_file($file) && filesize($file) > 0) {
                break;
            }

            usleep(20_000);
        }

        usleep(20_000);

        return (string) file_get_contents($file);
    }

    private function processes(): FrankenPhpProcesses
    {
        return new FrankenPhpProcesses(
            '/app/plugins',
            $this->state,
            new ProcessIdentity($this->proc),
            new PluginDependencies($this->state),
            '/app/docker/frankenphp/plugin.Caddyfile',
        );
    }

    /**
     * @param list<string> $command
     */
    private function recordPid(string $name, int $pid, array $command): void
    {
        file_put_contents($this->state.'/'.$name.'.pid', json_encode(['pid' => $pid, 'port' => 9100, 'command' => $command]));
    }

    /**
     * @param list<string> $command
     */
    private function fakeCommandLine(int $pid, array $command): void
    {
        mkdir($this->proc.'/'.$pid, 0o775, true);
        file_put_contents($this->proc.'/'.$pid.'/cmdline', implode("\0", $command)."\0");
    }
}
