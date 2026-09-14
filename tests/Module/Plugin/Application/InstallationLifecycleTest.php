<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Tests\Module\Plugin\Application;

use App\Module\Plugin\Application\ActivatePlugin;
use App\Module\Plugin\Application\ReadsManifests;
use App\Module\Plugin\Application\RemovePlugin;
use App\Module\Plugin\Domain\Manifest\ManifestReader;
use App\Module\Plugin\Domain\Manifest\TableReader;
use App\Module\Plugin\Domain\PluginAlreadyActive;
use App\Tests\Module\Plugin\Fixture\FlakyStorage;
use App\Tests\Module\Plugin\Fixture\InMemoryPlugins;
use App\Tests\Module\Plugin\Fixture\ManifestsOnDisk;
use App\Tests\Module\Plugin\Fixture\NoDeliveries;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Clock\MockClock;

/**
 * Aktivieren und Entfernen, wenn mittendrin etwas scheitert.
 *
 * Die Zusicherung ist nicht, dass beides gelingt — das sieht man ohnehin —,
 * sondern **dass ein Fehler keinen Rest hinterlaesst, den nur noch eine
 * Reparatur von Hand wegbekommt.** Beide Vorgaenge muessen sich danach in
 * der Oberflaeche wiederholen lassen.
 */
final class InstallationLifecycleTest extends TestCase
{
    private InMemoryPlugins $plugins;
    private FlakyStorage $storage;
    private ActivatePlugin $activate;
    private RemovePlugin $remove;

    protected function setUp(): void
    {
        $this->plugins = new InMemoryPlugins();
        $this->storage = new FlakyStorage();
        $manifests = new ReadsManifests(
            new ManifestsOnDisk(['reporting' => (string) json_encode([
                'api' => 1, 'name' => 'reporting', 'version' => '1.0.0', 'label' => ['de' => 'Berichte'],
            ])]),
            new ManifestReader(new TableReader()),
        );

        $this->activate = new ActivatePlugin($manifests, $this->plugins, $this->storage, new MockClock());
        $this->remove = new RemovePlugin($this->plugins, $this->storage, new NoDeliveries(), new NoDeliveries());
    }

    /**
     * Scheitert das Anlegen, bleibt keine Zeile — und der naechste Versuch gelingt.
     */
    public function testAFailedActivationCanBeRetried(): void
    {
        $this->storage->createFails = true;
        $this->activateExpectingFailure();

        self::assertNull($this->plugins->byName('reporting'));

        $this->storage->createFails = false;
        ($this->activate)('reporting', 'Prüfer');

        self::assertNotNull($this->plugins->byName('reporting'), 'Der zweite Versuch gelingt');
    }

    /**
     * Eine gescheiterte Aktivierung raeumt nie einen Speicher ab, den sie nicht gebaut hat.
     *
     * Der Fall: zwei Aktivierungen zugleich. Die erste hat ihren Speicher
     * angelegt, ihre Zeile aber noch nicht geschrieben; die zweite scheitert
     * am schon stehenden Schema. Raeumte sie danach auf, stuende die erste
     * mit einer Installation ohne Speicher da.
     */
    public function testAFailedActivationLeavesAnotherActivationsStorageStanding(): void
    {
        $this->storage->standing['reporting'] = true;
        $this->activateExpectingFailure();

        self::assertArrayHasKey('reporting', $this->storage->standing, 'Der Speicher der anderen Aktivierung steht noch');
    }

    /**
     * Scheitert das Abraeumen, bleibt die Installation — und laesst sich erneut entfernen.
     */
    public function testAFailedRemovalCanBeRetried(): void
    {
        ($this->activate)('reporting', 'Prüfer');
        $this->storage->dropFails = true;

        try {
            ($this->remove)('reporting');
            self::fail('Das Entfernen hätte scheitern müssen');
        } catch (RuntimeException) {
            // So soll es sein.
        }

        self::assertNotNull($this->plugins->byName('reporting'), 'Die Zeile steht noch — die Oberfläche kennt das Plugin');

        $this->storage->dropFails = false;
        ($this->remove)('reporting');

        self::assertNull($this->plugins->byName('reporting'));
        self::assertSame([], $this->storage->standing, 'Und danach ist wirklich nichts mehr da');
    }

    /** Wer zu spaet kommt, erfaehrt es — als eigener Fall, nicht als irgendein Fehler. */
    public function testASecondActivationIsReportedAsAlreadyActive(): void
    {
        ($this->activate)('reporting', 'Prüfer');

        $this->expectException(PluginAlreadyActive::class);

        ($this->activate)('reporting', 'Prüferin');
    }

    private function activateExpectingFailure(): void
    {
        try {
            ($this->activate)('reporting', 'Prüfer');
            self::fail('Das Aktivieren hätte scheitern müssen');
        } catch (RuntimeException $expected) {
            self::assertNotSame('Das Aktivieren hätte scheitern müssen', $expected->getMessage());
        }
    }
}
