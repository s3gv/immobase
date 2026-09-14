<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Shared\Flow;

/**
 * Der Zwischenstand eines laufenden Ablaufs.
 *
 * Werte werden pro Schritt gehalten, nicht in einem Topf. Nur so bleibt beim
 * Zurueckspringen erhalten, was in spaeteren Schritten schon eingegeben wurde.
 *
 * Angesprungen werden duerfen nur bereits erreichte Schritte. Sonst liesse
 * sich ueber die Adresszeile ein Schritt oeffnen, dessen Voraussetzungen noch
 * gar nicht erhoben sind.
 */
final class FlowState
{
    /**
     * @param array<string, array<string, mixed>> $values
     * @param list<string>                        $visited
     */
    private function __construct(
        private string $currentStepKey,
        private array $values,
        private array $visited,
    ) {
    }

    public static function start(FlowDefinition $definition): self
    {
        $first = $definition->firstStep()->key;

        return new self($first, [], [$first]);
    }

    /**
     * Ein Ablauf ueber einem bereits vorhandenen Datensatz.
     *
     * Alle Schritte gelten als erreicht. Damit wird die Schrittliste links zur
     * Abschnittsnavigation: wer nur eine Telefonnummer aendern will, springt
     * direkt dorthin, statt sich durch sechs Schritte zu klicken.
     *
     * @param array<string, array<string, mixed>> $values
     */
    public static function resume(FlowDefinition $definition, array $values): self
    {
        $visited = array_map(static fn (FlowStep $step): string => $step->key, $definition->steps());

        return new self($definition->firstStep()->key, $values, $visited);
    }

    /**
     * @param array{current: string, values: array<string, array<string, mixed>>, visited: list<string>} $data
     */
    public static function fromArray(array $data): self
    {
        return new self($data['current'], $data['values'], $data['visited']);
    }

    public function currentStepKey(): string
    {
        return $this->currentStepKey;
    }

    /**
     * @param array<string, mixed> $values
     */
    public function remember(string $stepKey, array $values): void
    {
        $this->values[$stepKey] = $values;
    }

    /**
     * @return array<string, mixed>
     */
    public function valuesFor(string $stepKey): array
    {
        return $this->values[$stepKey] ?? [];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function allValues(): array
    {
        return $this->values;
    }

    public function advance(FlowDefinition $definition): void
    {
        $next = $definition->next($this->currentStepKey);

        if (null === $next) {
            return;
        }

        $this->currentStepKey = $next->key;

        if (!\in_array($next->key, $this->visited, true)) {
            $this->visited[] = $next->key;
        }
    }

    public function goBack(FlowDefinition $definition): void
    {
        $previous = $definition->previous($this->currentStepKey);

        if (null === $previous) {
            return;
        }

        $this->currentStepKey = $previous->key;
    }

    /**
     * Springt zu einem Schritt, sofern er benutzbar ist.
     *
     * Unbrauchbare Namen werden schlicht ignoriert — unbekannte ebenso wie
     * noch nicht erreichte. Der Name kommt aus der Adresszeile und ist damit
     * Eingabe, kein Code: eine Ausnahme daraus zu machen hiesse, dass jeder
     * Aufrufer sie abfangen muss, und wer es vergisst, liefert einen
     * Serverfehler statt einer Seite.
     *
     * Das Nachschlagen bleibt dagegen streng: FlowDefinition wirft weiterhin,
     * wenn ein Schritt gezeichnet werden soll, den es nicht gibt. Streng beim
     * Nachschlagen, nachsichtig beim Navigieren.
     */
    public function jumpTo(FlowDefinition $definition, string $stepKey): void
    {
        if (!\in_array($stepKey, $this->visited, true) || !$definition->has($stepKey)) {
            return;
        }

        $this->currentStepKey = $stepKey;
    }

    public function hasVisited(string $stepKey): bool
    {
        return \in_array($stepKey, $this->visited, true);
    }

    /**
     * @return array{current: string, values: array<string, array<string, mixed>>, visited: list<string>}
     */
    public function toArray(): array
    {
        return ['current' => $this->currentStepKey, 'values' => $this->values, 'visited' => $this->visited];
    }
}
