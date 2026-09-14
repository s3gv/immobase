<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Tests\Shared\Region;

use App\Shared\Region\Background;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Stringable;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

final class BackgroundTest extends TestCase
{
    private const AVAILABLE = ['hessen', 'bayern', 'sachsen'];

    public function testUsesTheConfiguredRegion(): void
    {
        $region = new Background($this->requestStack(), new NullLogger(), 'bayern', self::AVAILABLE);

        self::assertSame('bayern', $region->region());
    }

    public function testChoosesFromTheAvailableOnesWhenAutomatic(): void
    {
        $region = new Background($this->requestStack(), new NullLogger(), Background::AUTOMATIC, self::AVAILABLE);

        self::assertContains($region->region(), self::AVAILABLE);
    }

    public function testKeepsTheChoiceForTheWholeSession(): void
    {
        $requests = $this->requestStack();
        $region = new Background($requests, new NullLogger(), Background::AUTOMATIC, self::AVAILABLE);

        $first = $region->region();

        // Der Umriss soll beim Blättern nicht springen.
        for ($i = 0; $i < 20; ++$i) {
            self::assertSame($first, $region->region());
        }
    }

    public function testDiscardsAStoredChoiceThatNoLongerExists(): void
    {
        $requests = $this->requestStack();
        $requests->getSession()->set('immobase.background_region', 'atlantis');

        $region = new Background($requests, new NullLogger(), Background::AUTOMATIC, self::AVAILABLE);

        self::assertContains($region->region(), self::AVAILABLE);
    }

    public function testFallsBackWhenNothingIsAvailable(): void
    {
        $region = new Background($this->requestStack(), new NullLogger(), Background::AUTOMATIC, []);

        self::assertSame(Background::AUTOMATIC, $region->region());
    }

    public function testFallsBackWhenTheConfiguredRegionDoesNotExist(): void
    {
        $logger = new class extends NullLogger {
            /** @var list<string> */
            public array $warnings = [];

            public function warning(string|Stringable $message, array $context = []): void
            {
                $this->warnings[] = (string) $message;
            }
        };

        // Der Wert wird zu einem Dateinamen. Ohne Prüfung endete jede Seite
        // mit 500, weil Twig die Vorlage nicht findet.
        $region = new Background($this->requestStack(), $logger, 'gibtesnicht', self::AVAILABLE);

        self::assertContains($region->region(), self::AVAILABLE);
        self::assertCount(1, $logger->warnings, 'Der Tippfehler soll nicht stillschweigend übergangen werden');
    }

    public function testFallsBackForAValueThatDiffersOnlyInCase(): void
    {
        // Auf macOS löst das Dateisystem "Hessen" zu "hessen" auf, auf Linux
        // nicht — der Fehler zeigte sich lokal deshalb nicht.
        $region = new Background($this->requestStack(), new NullLogger(), 'Hessen', self::AVAILABLE);

        self::assertContains($region->region(), self::AVAILABLE);
    }

    private function requestStack(): RequestStack
    {
        $request = new Request();
        $request->setSession(new Session(new MockArraySessionStorage()));

        $stack = new RequestStack();
        $stack->push($request);

        return $stack;
    }
}
