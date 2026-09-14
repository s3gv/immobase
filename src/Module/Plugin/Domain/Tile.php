<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Plugin\Domain;

use DateTimeImmutable;

/**
 * Eine Kachel, die ein Plugin auf die Uebersicht stellt.
 *
 * Der Wert kommt fertig geschrieben an — nur das Plugin weiss, ob seine Zahl
 * ein Betrag ist, eine Anzahl oder ein Anteil.
 */
final readonly class Tile
{
    /** Die Toene, die es gibt. Ein erfundener wuerde nichts einfaerben. */
    public const array TONES = ['neutral', 'success', 'warning', 'danger'];

    /**
     * @param array<string, string> $labels
     */
    public function __construct(
        public string $plugin,
        public string $key,
        public array $labels,
        public string $value,
        public string $tone,
        public string $path,
        public DateTimeImmutable $seenAt,
    ) {
    }

    public function label(string $locale): string
    {
        return $this->labels[$locale] ?? $this->labels['en'] ?? $this->key;
    }
}
