<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Tests\Module\Plugin\Infrastructure;

use App\Module\Plugin\Infrastructure\PluginRunner;
use PHPUnit\Framework\TestCase;

/**
 * Plugin-Prozesse unter einem eigenen Benutzer.
 *
 * Im Image liegt dafuer ein kleiner Helfer, der nur auf den Plugin-Benutzer
 * wechseln kann. Ohne ihn (auf dem Entwicklungsrechner, in den Tests) laeuft
 * alles wie bisher unter dem eigenen Benutzer.
 */
final class PluginRunnerTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir().'/immobase-runner-'.bin2hex(random_bytes(4));
        mkdir($this->directory, 0o775, true);
    }

    protected function tearDown(): void
    {
        exec('rm -rf '.escapeshellarg($this->directory));
    }

    public function testWithoutAHelperTheCommandStaysAsItIs(): void
    {
        $runner = new PluginRunner(null);

        self::assertSame(['frankenphp', 'run'], $runner->wrap(['frankenphp', 'run'], 9100));
        self::assertSame('/var/plugins/reporting.caddy', $runner->homeFor('/var/plugins', 'reporting', 9100));
    }

    /** Jedes Plugin unter seiner eigenen Kennung — sonst laese eines das Token des anderen. */
    public function testWithAHelperEachPluginRunsUnderItsOwnId(): void
    {
        $runner = new PluginRunner('/usr/local/libexec/immobase/run-as-plugin');

        self::assertSame(
            ['/usr/local/libexec/immobase/run-as-plugin', 'exec', '109100', 'frankenphp', 'run'],
            $runner->wrap(['frankenphp', 'run'], 9100),
        );
        self::assertNotSame($runner->wrap(['frankenphp'], 9100), $runner->wrap(['frankenphp'], 9101));
    }

    /**
     * Der Plugin-Benutzer darf nicht in die Zustandsdateien des Cores schreiben.
     * Sein Caddy bekommt ein Zuhause, das er selbst anlegen kann.
     */
    public function testWithAHelperCaddyLivesOutsideTheCoresState(): void
    {
        $home = (new PluginRunner('/usr/local/libexec/immobase/run-as-plugin'))->homeFor('/app/var/plugins', 'reporting', 9100);

        self::assertSame('/tmp/immobase-plugin-109100', $home, 'Derselbe Pfad, den der Helfer anlegt und prueft');
    }

    /** Beenden geht ueber den Helfer: ein anderer Benutzer darf dem Prozess kein Signal schicken. */
    public function testTerminatingGoesThroughTheHelper(): void
    {
        $helper = $this->directory.'/run-as-plugin';
        file_put_contents($helper, "#!/bin/sh\necho \"\$@\" > ".escapeshellarg($this->directory.'/called')."\n");
        chmod($helper, 0o755);

        (new PluginRunner($helper))->terminate(4242, 9100);

        self::assertSame("kill 109100 4242\n", file_get_contents($this->directory.'/called'));
    }
}
