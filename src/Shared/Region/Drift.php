<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Shared\Region;

use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Kennwerte der Hintergrundbewegung.
 *
 * Die Position ist eine reine Funktion der absoluten Uhrzeit: je zwei
 * ueberlagerte Schwingungen pro Achse mit teilerfremden Perioden. Daraus folgt
 * dreierlei.
 *
 * Die Bahn wiederholt sich praktisch nie — anders als eine CSS-Animation, die
 * zwangslaeufig dieselbe Bahn ablaeuft und umkehrt.
 *
 * Die Bewegung bleibt durch die Amplituden beschraenkt, sodass immer etwas im
 * Bild ist und der Umriss nicht davonwandert.
 *
 * Beim Neuladen und beim Seitenwechsel gibt es keinen Sprung, weil kein Zustand
 * mitgefuehrt wird, der verloren gehen koennte.
 */
final readonly class Drift
{
    private const SESSION_KEY = 'immobase.background_drift';

    /** Anteil der ersten und zweiten Schwingung an der Gesamtamplitude. */
    private const FIRST_WEIGHT = 0.67;
    private const SECOND_WEIGHT = 0.33;

    public function __construct(private RequestStack $requests)
    {
    }

    /**
     * @return array{
     *     centreX: int, centreY: int,
     *     amplitudeX: int, amplitudeY: int,
     *     periods: array{int, int, int, int}, phases: array{float, float, float, float}
     * }
     */
    public function parameters(): array
    {
        // Ohne Sitzung — eine Fehlerseite, die schon vor dem Sitzungsaufbau
        // entsteht, etwa bei einem fremden Host — einfach frisch gewuerfelt.
        // Eine Dekoration darf die Fehlerseite nicht selbst zum Fehler machen.
        if (true !== $this->requests->getCurrentRequest()?->hasSession()) {
            return self::random();
        }

        $session = $this->requests->getSession();
        $restored = self::restore($session->get(self::SESSION_KEY));

        if (null !== $restored) {
            return $restored;
        }

        $fresh = self::random();
        $session->set(self::SESSION_KEY, $fresh);

        return $fresh;
    }

    /**
     * Die Position zum Zeitpunkt des Renderns, als fertiger CSS-Wert.
     *
     * Ohne sie zeichnet der Browser zuerst die Rueckfallposition aus dem
     * Stylesheet und springt erst dann an die richtige Stelle, sobald das Modul
     * geladen ist. Wird sie hier berechnet, stimmt schon das erste Bild.
     *
     * Dieselbe Formel wie in assets/controllers/background.js. Sie steht
     * bewusst zweimal da: die Alternative waere ein zweites Stueck eingebettetes
     * JavaScript im Seitenkopf, fuer eine Dekoration.
     */
    public function initialTransform(): string
    {
        $p = $this->parameters();
        $seconds = (float) time();

        return \sprintf(
            'translate(%.2f%%, %.2f%%)',
            self::axis($p['centreX'], $p['amplitudeX'], $p['periods'][0], $p['periods'][1], $p['phases'][0], $p['phases'][1], $seconds),
            self::axis($p['centreY'], $p['amplitudeY'], $p['periods'][2], $p['periods'][3], $p['phases'][2], $p['phases'][3], $seconds),
        );
    }

    private static function axis(
        int $centre,
        int $amplitude,
        float $firstPeriod,
        float $secondPeriod,
        float $firstPhase,
        float $secondPhase,
        float $seconds,
    ): float {
        return $centre
            + $amplitude * self::FIRST_WEIGHT * sin(2 * \M_PI * $seconds / $firstPeriod + $firstPhase)
            + $amplitude * self::SECOND_WEIGHT * sin(2 * \M_PI * $seconds / $secondPeriod + $secondPhase);
    }

    /**
     * @return array{
     *     centreX: int, centreY: int,
     *     amplitudeX: int, amplitudeY: int,
     *     periods: array{int, int, int, int}, phases: array{float, float, float, float}
     * }
     */
    private static function random(): array
    {
        // Primzahlen als Perioden in Sekunden. Teilerfremd gewaehlt, damit die
        // Ueberlagerung keine kurze gemeinsame Periode bekommt und sich die
        // Bahn nicht sichtbar wiederholt.
        $candidates = [181, 227, 269, 313, 367, 421, 463, 521];
        shuffle($candidates);

        return [
            'centreX' => -48,
            'centreY' => -46,
            'amplitudeX' => random_int(4, 7),
            'amplitudeY' => random_int(4, 7),
            'periods' => self::fourPeriods($candidates),
            'phases' => [self::phase(), self::phase(), self::phase(), self::phase()],
        ];
    }

    /**
     * @param list<int> $candidates
     *
     * @return array{int, int, int, int}
     */
    private static function fourPeriods(array $candidates): array
    {
        // Die Liste hat acht Eintraege, aber shuffle nimmt der statischen
        // Analyse die Laenge. Der Rueckfall macht die Zusicherung belegbar.
        return isset($candidates[0], $candidates[1], $candidates[2], $candidates[3])
            ? [$candidates[0], $candidates[1], $candidates[2], $candidates[3]]
            : [181, 227, 269, 313];
    }

    private static function phase(): float
    {
        return random_int(0, 6283) / 1000;
    }

    /**
     * @return array{
     *     centreX: int, centreY: int,
     *     amplitudeX: int, amplitudeY: int,
     *     periods: array{int, int, int, int}, phases: array{float, float, float, float}
     * }|null
     */
    private static function restore(mixed $stored): ?array
    {
        if (!\is_array($stored)) {
            return null;
        }

        foreach (['centreX', 'centreY', 'amplitudeX', 'amplitudeY'] as $key) {
            if (!\is_int($stored[$key] ?? null)) {
                return null;
            }
        }

        $periods = self::numberList($stored['periods'] ?? null);
        $phases = self::numberList($stored['phases'] ?? null);

        if (null === $periods || null === $phases) {
            return null;
        }

        return [
            'centreX' => $stored['centreX'],
            'centreY' => $stored['centreY'],
            'amplitudeX' => $stored['amplitudeX'],
            'amplitudeY' => $stored['amplitudeY'],
            'periods' => [(int) $periods[0], (int) $periods[1], (int) $periods[2], (int) $periods[3]],
            'phases' => $phases,
        ];
    }

    /**
     * Genau vier Zahlen, sonst nichts.
     *
     * Sitzungsdaten koennen alt oder beschaedigt sein — geprueft statt
     * behauptet.
     *
     * @return array{float, float, float, float}|null
     */
    private static function numberList(mixed $value): ?array
    {
        if (!\is_array($value)) {
            return null;
        }

        $numbers = [];

        foreach (array_values($value) as $item) {
            if (!\is_int($item) && !\is_float($item)) {
                return null;
            }

            $numbers[] = (float) $item;
        }

        // isset statt count: nur so ist fuer die statische Analyse belegt, dass
        // die vier Positionen wirklich existieren.
        return isset($numbers[0], $numbers[1], $numbers[2], $numbers[3]) && 4 === \count($numbers)
            ? [$numbers[0], $numbers[1], $numbers[2], $numbers[3]]
            : null;
    }
}
