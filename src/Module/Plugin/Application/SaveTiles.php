<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Plugin\Application;

use App\Module\Plugin\Domain\Tile;
use App\Module\Plugin\Domain\TileRepository;
use DateTimeImmutable;
use Symfony\Component\Clock\ClockInterface;

/**
 * Was ein Plugin an Kacheln abliefert.
 *
 * **Nichts davon wird geglaubt, ohne es anzusehen.** Ein Ton, den es nicht
 * gibt, waere eine Kachel ohne Farbe; ein Pfad, der aus dem Plugin
 * herausfuehrt, waere ein Link irgendwohin; ein langer Wert spraengte die
 * Kachel. Was nicht passt, faellt raus — und wenn gar nichts passt, hat das
 * Plugin eben keine Kacheln.
 */
final readonly class SaveTiles
{
    private const int MOST = 6;

    public function __construct(
        private TileRepository $tiles,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @param list<array<string, mixed>> $sent
     */
    public function __invoke(string $plugin, array $sent): void
    {
        $now = $this->clock->now();
        $found = [];

        foreach (\array_slice($sent, 0, self::MOST) as $entry) {
            $tile = self::tileFrom($plugin, $entry, $now);

            if (null !== $tile) {
                $found[$tile->key] = $tile;
            }
        }

        $this->tiles->replace($plugin, array_values($found));
    }

    /**
     * @param array<string, mixed> $entry
     * @param DateTimeImmutable    $now
     */
    private static function tileFrom(string $plugin, array $entry, DateTimeImmutable $now): ?Tile
    {
        $key = self::text($entry, 'key');
        $value = self::text($entry, 'value');
        $labels = self::labels($entry);

        if (1 !== preg_match('/^[a-z][a-z0-9_]{0,63}$/D', $key) || '' === $value || [] === $labels) {
            return null;
        }

        $tone = self::text($entry, 'tone');
        $path = self::text($entry, 'path');

        return new Tile(
            $plugin,
            $key,
            $labels,
            mb_substr($value, 0, 64),
            \in_array($tone, Tile::TONES, true) ? $tone : 'neutral',
            self::pathOrNothing($path),
            $now,
        );
    }

    /**
     * Ein Pfad beim Plugin — oder gar keiner.
     *
     * Er beginnt mit einem Schraegstrich und fuehrt nicht heraus: weder in
     * ein anderes Verzeichnis noch auf einen fremden Rechner. Was nicht
     * passt, wird zu „kein Link" und nicht zu einem Fehler: eine Kachel ohne
     * Verweis ist immer noch eine Kachel.
     */
    private static function pathOrNothing(string $path): string
    {
        $leaves = !str_starts_with($path, '/') || str_starts_with($path, '//') || str_contains($path, '..');

        return '' === $path || $leaves ? '' : mb_substr($path, 0, 200);
    }

    /**
     * @param array<string, mixed> $entry
     *
     * @return array<string, string>
     */
    private static function labels(array $entry): array
    {
        $labels = $entry['label'] ?? null;
        $found = [];

        if (!\is_array($labels)) {
            return [];
        }

        foreach ($labels as $locale => $label) {
            if (\is_string($locale) && \is_string($label) && '' !== $label) {
                $found[$locale] = mb_substr($label, 0, 80);
            }
        }

        return $found;
    }

    /**
     * @param array<string, mixed> $entry
     */
    private static function text(array $entry, string $key): string
    {
        $value = $entry[$key] ?? '';

        return \is_scalar($value) ? (string) $value : '';
    }
}
