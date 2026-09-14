<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Plugin\Domain\Manifest;

/**
 * Gepruefter Zugriff auf das, was in einem Manifest steht.
 *
 * Ein Manifest ist eine fremde Datei. Jede Abfrage sagt deshalb, was sie
 * erwartet, und meldet die Stelle mit, an der es nicht passt — `nav[1].path`
 * statt „ungueltiges Manifest". Wer sein erstes Plugin baut, soll den Fehler
 * finden, ohne unseren Quelltext zu lesen.
 */
final readonly class Fields
{
    /**
     * @param array<array-key, mixed> $data
     */
    public function __construct(
        private array $data,
        private string $where,
    ) {
    }

    public function text(string $key, string $pattern = ''): string
    {
        $value = $this->data[$key] ?? null;

        if (!\is_string($value) || '' === $value) {
            throw ManifestFault::at($this->at($key), 'erwartet eine nicht leere Zeichenkette');
        }

        if ('' !== $pattern && 1 !== preg_match($pattern, $value)) {
            throw ManifestFault::at($this->at($key), \sprintf('„%s" passt nicht auf %s', $value, $pattern));
        }

        return $value;
    }

    public function textOr(string $key, string $fallback, string $pattern = ''): string
    {
        return \array_key_exists($key, $this->data) ? $this->text($key, $pattern) : $fallback;
    }

    public function integer(string $key): int
    {
        $value = $this->data[$key] ?? null;

        if (!\is_int($value)) {
            throw ManifestFault::at($this->at($key), 'erwartet eine ganze Zahl');
        }

        return $value;
    }

    public function flag(string $key, bool $fallback = false): bool
    {
        $value = $this->data[$key] ?? $fallback;

        if (!\is_bool($value)) {
            throw ManifestFault::at($this->at($key), 'erwartet wahr oder falsch');
        }

        return $value;
    }

    /**
     * Beschriftungen je Sprache.
     *
     * @return non-empty-array<string, string>
     */
    public function labels(string $key): array
    {
        $value = $this->data[$key] ?? null;
        $labels = [];

        if (!\is_array($value) || [] === $value) {
            throw ManifestFault::at($this->at($key), 'erwartet Beschriftungen je Sprache, etwa {"de": …, "en": …}');
        }

        foreach ($value as $locale => $label) {
            if (!\is_string($locale) || !\is_string($label) || '' === $label) {
                throw ManifestFault::at($this->at($key), 'erwartet Sprache und Beschriftung als Zeichenketten');
            }

            $labels[$locale] = $label;
        }

        return $labels;
    }

    /**
     * Eine Liste von Zeichenketten; fehlt sie, ist sie leer.
     *
     * @return list<string>
     */
    public function strings(string $key, string $pattern = ''): array
    {
        $value = $this->data[$key] ?? [];
        $found = [];

        if (!\is_array($value)) {
            throw ManifestFault::at($this->at($key), 'erwartet eine Liste');
        }

        foreach (array_values($value) as $index => $entry) {
            if (!\is_string($entry) || ('' !== $pattern && 1 !== preg_match($pattern, $entry))) {
                throw ManifestFault::at($this->at($key).'['.$index.']', 'passt nicht auf '.('' === $pattern ? 'eine Zeichenkette' : $pattern));
            }

            $found[] = $entry;
        }

        return $found;
    }

    /**
     * Eine Liste von Unterobjekten; fehlt sie, ist sie leer.
     *
     * @return list<self>
     */
    public function each(string $key): array
    {
        $value = $this->data[$key] ?? [];
        $found = [];

        if (!\is_array($value)) {
            throw ManifestFault::at($this->at($key), 'erwartet eine Liste von Objekten');
        }

        foreach (array_values($value) as $index => $entry) {
            if (!\is_array($entry)) {
                throw ManifestFault::at($this->at($key).'['.$index.']', 'erwartet ein Objekt');
            }

            $found[] = new self($entry, $this->at($key).'['.$index.']');
        }

        return $found;
    }

    private function at(string $key): string
    {
        return '' === $this->where ? $key : $this->where.'.'.$key;
    }
}
