<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Party\Application;

/**
 * Die rohen Eingaben eines Ablaufs, Schritt fuer Schritt und Feld fuer Feld.
 *
 * Zwischen zwei Aufrufen liegt eine Sitzung, und was von dort zurueckkommt,
 * ist ein verschachteltes Feld aus `mixed` — jede Angabe koennte fehlen, jede
 * koennte etwas anderes sein als erwartet. Genau diese Vorsicht steht hier
 * und nicht im Entwurf: {@see PartyDraft} beantwortet fachliche Fragen, diese
 * Klasse beantwortet nur die eine, wie man an einen Wert kommt.
 *
 * Was fehlt, ist leer. Keine Ausnahme, kein `null`: ein halb ausgefuellter
 * Entwurf ist der Normalfall eines Ablaufs, in dem jeder Schritt einzeln
 * ausgefuellt wird.
 */
final readonly class DraftValues
{
    /**
     * @param array<string, array<string, mixed>> $values
     */
    public function __construct(private array $values)
    {
    }

    public function text(string $step, string $field): string
    {
        $value = $this->values[$step][$field] ?? '';

        return \is_string($value) ? $value : '';
    }

    /**
     * Eine Liste von Textangaben, unveraendert in ihrer Zahl.
     *
     * @return list<string>
     */
    public function texts(string $step, string $field): array
    {
        $values = $this->values[$step][$field] ?? [];

        if (!\is_array($values)) {
            return [];
        }

        return array_map(static fn (mixed $v): string => \is_string($v) ? trim($v) : '', array_values($values));
    }

    /**
     * @return list<array<mixed>>
     */
    public function rows(string $step, string $field): array
    {
        $values = $this->values[$step][$field] ?? [];

        if (!\is_array($values)) {
            return [];
        }

        return array_values(array_filter($values, \is_array(...)));
    }

    /**
     * Ein Feld einer Zeile.
     *
     * @param array<mixed> $entry
     */
    public static function field(array $entry, string $key): string
    {
        $value = $entry[$key] ?? '';

        return \is_string($value) ? trim($value) : '';
    }

    /**
     * Was leer ist, faellt weg.
     *
     * @param list<string> $values
     *
     * @return list<string>
     */
    public static function withoutBlanks(array $values): array
    {
        return array_values(array_filter($values, static fn (string $value): bool => '' !== $value));
    }
}
