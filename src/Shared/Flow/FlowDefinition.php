<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Shared\Flow;

use InvalidArgumentException;

/**
 * Die Schrittfolge eines gefuehrten Ablaufs.
 *
 * Fachlich neutral: die Klasse weiss nichts ueber Abrechnungen, Mietvertraege
 * oder Objekte. Ein Fachmodul beschreibt seinen Ablauf damit, statt ihn zu
 * bauen.
 */
final readonly class FlowDefinition
{
    /** @var list<FlowStep> */
    private array $steps;

    /**
     * @param list<FlowStep> $steps
     */
    public function __construct(public string $id, array $steps)
    {
        if ([] === $steps) {
            throw new InvalidArgumentException(\sprintf('Der Ablauf "%s" braucht mindestens einen Schritt.', $id));
        }

        $keys = array_map(static fn (FlowStep $step): string => $step->key, $steps);

        if (\count($keys) !== \count(array_unique($keys))) {
            throw new InvalidArgumentException(\sprintf('Der Ablauf "%s" hat doppelt vergebene Schrittnamen.', $id));
        }

        $this->steps = array_values($steps);
    }

    /**
     * @return list<FlowStep>
     */
    public function steps(): array
    {
        return $this->steps;
    }

    public function firstStep(): FlowStep
    {
        return $this->steps[0];
    }

    public function has(string $key): bool
    {
        foreach ($this->steps as $step) {
            if ($step->key === $key) {
                return true;
            }
        }

        return false;
    }

    public function step(string $key): FlowStep
    {
        foreach ($this->steps as $step) {
            if ($step->key === $key) {
                return $step;
            }
        }

        throw UnknownFlowStep::named($key, $this->id);
    }

    public function next(string $key): ?FlowStep
    {
        return $this->neighbour($key, 1);
    }

    public function previous(string $key): ?FlowStep
    {
        return $this->neighbour($key, -1);
    }

    public function positionOf(string $key): int
    {
        return $this->indexOf($key) + 1;
    }

    public function stepCount(): int
    {
        return \count($this->steps);
    }

    public function isLast(string $key): bool
    {
        return $this->indexOf($key) === \count($this->steps) - 1;
    }

    /**
     * Der Nachbar eines Schritts, oder keiner am Rand der Folge.
     *
     * Die Suche laeuft ueber die Schritte statt ueber einen Index-Zugriff:
     * so ist jedem Leser — und dem Analysator — ohne Umweg klar, dass hier
     * nur ein tatsaechlich vorhandener Schritt herauskommen kann.
     */
    private function neighbour(string $key, int $offset): ?FlowStep
    {
        $wanted = $this->indexOf($key) + $offset;

        foreach ($this->steps as $position => $step) {
            if ($position === $wanted) {
                return $step;
            }
        }

        return null;
    }

    private function indexOf(string $key): int
    {
        foreach ($this->steps as $index => $step) {
            if ($step->key === $key) {
                return $index;
            }
        }

        throw UnknownFlowStep::named($key, $this->id);
    }
}
