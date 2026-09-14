<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Tests\Shared\Region;

use App\Shared\Region\Drift;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

final class DriftTest extends TestCase
{
    public function testDriftCarriesBoundedParameters(): void
    {
        $drift = (new Drift($this->requestStack()))->parameters();

        self::assertCount(4, $drift['periods']);
        self::assertCount(4, $drift['phases']);

        // Die Amplituden begrenzen die Bewegung. Ohne Grenze könnte der Umriss
        // aus dem Bild wandern und der Hintergrund leer bleiben.
        self::assertGreaterThan(0, $drift['amplitudeX']);
        self::assertLessThanOrEqual(10, $drift['amplitudeX']);
        self::assertGreaterThan(0, $drift['amplitudeY']);
        self::assertLessThanOrEqual(10, $drift['amplitudeY']);
    }

    public function testDriftPeriodsAreDistinct(): void
    {
        $drift = (new Drift($this->requestStack()))->parameters();

        // Gleiche Perioden ergäben eine kurze gemeinsame Periode, und die Bahn
        // würde sich sichtbar wiederholen.
        self::assertSame($drift['periods'], array_values(array_unique($drift['periods'])));
    }

    public function testDriftStaysTheSameWithinASession(): void
    {
        $drift = new Drift($this->requestStack());

        self::assertSame($drift->parameters(), $drift->parameters());
    }

    public function testDriftIgnoresBrokenSessionData(): void
    {
        $requests = $this->requestStack();
        $requests->getSession()->set('immobase.background_drift', ['periods' => 'kaputt']);

        $drift = (new Drift($requests))->parameters();

        self::assertCount(4, $drift['periods']);
    }

    public function testInitialTransformIsAReadyCssValue(): void
    {
        $drift = new Drift($this->requestStack());

        // Ohne serverseitige Startposition zeichnet der Browser erst die
        // Rückfallposition und springt dann — genau der sichtbare Ruckler.
        self::assertMatchesRegularExpression(
            '/^translate\\(-?\\d+\\.\\d\\d%, -?\\d+\\.\\d\\d%\\)$/',
            $drift->initialTransform(),
        );
    }

    public function testInitialTransformStaysWithinTheAmplitude(): void
    {
        $drift = new Drift($this->requestStack());
        $parameters = $drift->parameters();

        self::assertSame(1, preg_match('/translate\\((-?[\\d.]+)%, (-?[\\d.]+)%\\)/', $drift->initialTransform(), $m));

        // Der Umriss darf nicht aus dem Bild wandern.
        self::assertLessThanOrEqual($parameters['amplitudeX'], abs((float) $m[1] - $parameters['centreX']));
        self::assertLessThanOrEqual($parameters['amplitudeY'], abs((float) $m[2] - $parameters['centreY']));
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
