<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Dashboard\Application;

use App\Module\Dashboard\Contract\TodoGroup;
use App\Shared\Todo\ContributesTodos;
use App\Shared\Todo\Todo;
use App\Shared\Todo\TodoKind;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * Alles, was auf einen Menschen wartet — aus allen Modulen.
 *
 * Sie kennt kein Fachmodul: sie fragt, wer sich als
 * {@see ContributesTodos} gemeldet hat, sortiert und gruppiert. Rechte
 * prueft sie nicht — das tut jede Quelle fuer sich.
 */
final readonly class CollectsTodos
{
    /** So viele Zeilen zeigt eine Gruppe, der Rest wird gezaehlt. */
    public const int SHOWN = 5;

    /**
     * @param iterable<ContributesTodos> $sources
     */
    public function __construct(
        #[AutowireIterator('todo.source')]
        private iterable $sources,
    ) {
    }

    /**
     * @return list<TodoGroup> nur Gruppen, in denen etwas steht
     */
    public function groups(): array
    {
        $byKind = $this->collected();
        $groups = [];

        foreach (TodoKind::inOrder() as $kind) {
            $todos = $byKind[$kind->value] ?? [];

            if ([] === $todos) {
                continue;
            }

            usort($todos, self::mostPressingFirst(...));
            $groups[] = new TodoGroup($kind, \array_slice($todos, 0, self::SHOWN), max(0, \count($todos) - self::SHOWN));
        }

        return $groups;
    }

    /**
     * @return array<string, list<Todo>>
     */
    private function collected(): array
    {
        $byKind = [];

        foreach ($this->sources as $source) {
            foreach ($source->todos() as $todo) {
                $byKind[$todo->kind->value][] = $todo;
            }
        }

        return $byKind;
    }

    /**
     * Dringend zuerst, dann das, was zuerst ablaeuft, dann das Groesste.
     *
     * Ohne Datum steht ein Todo hinter denen mit Datum: was ein Datum hat,
     * hat einen Zeitpunkt, an dem es zu spaet ist.
     */
    private static function mostPressingFirst(Todo $a, Todo $b): int
    {
        $byUrgency = $b->urgency->weight() <=> $a->urgency->weight();

        if (0 !== $byUrgency) {
            return $byUrgency;
        }

        $byDate = self::dateOf($a) <=> self::dateOf($b);

        return 0 !== $byDate ? $byDate : $b->count <=> $a->count;
    }

    private static function dateOf(Todo $todo): string
    {
        return $todo->dueOn?->format('Y-m-d') ?? '9999-12-31';
    }
}
