<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Shared\Flow;

use Symfony\Component\HttpFoundation\Request;

/**
 * Eine Anweisung an eine Liste innerhalb eines Schritts.
 *
 * Schritte enthalten oft mehrere gleichartige Angaben — Anschriften,
 * E-Mail-Adressen, Telefonnummern. Hinzufuegen, Entfernen und "das ist die
 * wichtigste" laufen ueber Knoepfe, deren Wert die Anweisung traegt. Damit
 * kommt das Bearbeiten von Listen ohne JavaScript aus: die Seite wird schlicht
 * neu gezeichnet.
 *
 * Die erste Angabe einer Liste ist immer die wichtigste. Die Reihenfolge
 * traegt die Bedeutung, es gibt kein zusaetzliches Kennzeichen — zwei Angaben
 * fuer dieselbe Sache koennen einander widersprechen, eine Reihenfolge nicht.
 */
final readonly class ListInstruction
{
    private function __construct(
        public string $action,
        public string $field,
        public string $argument,
    ) {
    }

    public static function from(Request $request): ?self
    {
        foreach (['add', 'remove', 'promote'] as $action) {
            $value = $request->request->getString($action);

            if ('' !== $value) {
                $parts = explode(':', $value, 2);

                return new self($action, $parts[0] ?? '', $parts[1] ?? '');
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $step     die Angaben des Schritts
     * @param mixed                $addition was beim Hinzufuegen entsteht
     *
     * @return array<string, mixed>
     */
    public function applyTo(array $step, mixed $addition): array
    {
        $list = \is_array($step[$this->field] ?? null) ? array_values($step[$this->field]) : [];

        $step[$this->field] = match ($this->action) {
            'add' => [...$list, $addition],
            'remove' => self::without($list, (int) $this->argument),
            'promote' => self::promoted($list, (int) $this->argument),
            default => $list,
        };

        return $step;
    }

    /**
     * @param list<mixed> $list
     *
     * @return list<mixed>
     */
    private static function without(array $list, int $position): array
    {
        unset($list[$position]);

        return array_values($list);
    }

    /**
     * @param list<mixed> $list
     *
     * @return list<mixed>
     */
    private static function promoted(array $list, int $position): array
    {
        if (!\array_key_exists($position, $list)) {
            return $list;
        }

        $chosen = $list[$position];
        unset($list[$position]);

        return [$chosen, ...array_values($list)];
    }
}
