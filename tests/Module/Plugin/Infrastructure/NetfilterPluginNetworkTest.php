<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Tests\Module\Plugin\Infrastructure;

use App\Module\Plugin\Infrastructure\NetfilterPluginNetwork;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Die Netzsperre ueber den Helfer im Image.
 *
 * Geprueft wird hier, was der Core dem Helfer sagt: den Port der
 * Schnittstelle, die Datenbank als Adresse und die Kennungen mit Internet.
 * Die Regeln selbst wirken nur im Container und sind dort nachgeprueft.
 */
final class NetfilterPluginNetworkTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir().'/immobase-firewall-'.bin2hex(random_bytes(4));
        mkdir($this->directory, 0o775, true);
    }

    protected function tearDown(): void
    {
        exec('rm -rf '.escapeshellarg($this->directory));
    }

    public function testTheHelperLearnsPortsTheDatabaseAddressAndWhoMayUseTheInternet(): void
    {
        $this->network('exit 0')->restrict([9100, 9102]);

        self::assertSame("2080 5433 127.0.0.1 109100 109102\n", $this->calls());
    }

    /** Nur wenn sich etwas aendert: der Hintergrundlauf fragt alle fuenf Sekunden. */
    public function testAnUnchangedLockIsNotWrittenAgain(): void
    {
        $network = $this->network('exit 0');

        $network->restrict([9100]);
        $network->restrict([9100]);
        $network->restrict([]);

        self::assertSame("2080 5433 127.0.0.1 109100\n2080 5433 127.0.0.1\n", $this->calls());
    }

    /** Scheitert der Helfer, erfaehrt es der Aufseher — und startet nichts. */
    public function testAFailingHelperIsReportedWithItsReason(): void
    {
        try {
            $this->network('echo "CAP_NET_ADMIN fehlt" >&2; exit 111')->restrict([]);
            self::fail('Der Fehler hätte gemeldet werden müssen');
        } catch (RuntimeException $failed) {
            self::assertStringContainsString('CAP_NET_ADMIN fehlt', $failed->getMessage());
        }
    }

    public function testWithoutAHelperThereIsNothingToDo(): void
    {
        (new NetfilterPluginNetwork(null, 'http://127.0.0.1:2080', DriverManager::getConnection(['driver' => 'pdo_pgsql', 'host' => '127.0.0.1'])))->restrict([9100]);

        self::assertFileDoesNotExist($this->directory.'/calls');
    }

    private function network(string $then): NetfilterPluginNetwork
    {
        $helper = $this->directory.'/plugin-firewall';
        file_put_contents($helper, "#!/bin/sh\necho \"\$@\" >> ".escapeshellarg($this->directory.'/calls')."\n".$then."\n");
        chmod($helper, 0o755);

        return new NetfilterPluginNetwork(
            $helper,
            'http://127.0.0.1:2080',
            DriverManager::getConnection(['driver' => 'pdo_pgsql', 'host' => '127.0.0.1', 'port' => 5433]),
        );
    }

    private function calls(): string
    {
        return (string) @file_get_contents($this->directory.'/calls');
    }
}
