<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Auth\Domain\Rbac;

/**
 * Eine Menge von Rechteschluesseln.
 *
 * Ein eigenes Wertobjekt und kein `array`, weil die Menge staendig vereinigt
 * und verglichen wird — Rollen mit Rollen, Rollen mit direkten Zuweisungen.
 * Mit blossen Listen stuende an jeder dieser Stellen ein `array_unique` und
 * ein `array_values`, und einmal wuerde eines fehlen.
 *
 * Unbekannte Schluessel darf sie enthalten: die Datenbank kann Zeilen halten,
 * deren Bereich es nicht mehr gibt. Aussortiert werden sie erst dort, wo der
 * Katalog bekannt ist.
 */
final readonly class GrantedPermissions
{
    /** @var array<string, true> */
    private array $keys;

    /**
     * @param iterable<string> $keys
     */
    private function __construct(iterable $keys)
    {
        $set = [];

        foreach ($keys as $key) {
            $set[$key] = true;
        }

        ksort($set);

        $this->keys = $set;
    }

    /**
     * @param iterable<string> $keys
     */
    public static function of(iterable $keys): self
    {
        return new self($keys);
    }

    public static function none(): self
    {
        return new self([]);
    }

    public function with(self $other): self
    {
        return new self([...$this->toList(), ...$other->toList()]);
    }

    public function has(string $key): bool
    {
        return isset($this->keys[$key]);
    }

    public function isEmpty(): bool
    {
        return [] === $this->keys;
    }

    public function count(): int
    {
        return \count($this->keys);
    }

    /**
     * Nur die Schluessel, die es noch gibt.
     *
     * @param list<string> $known
     */
    public function knownOnly(array $known): self
    {
        return new self(array_intersect($this->toList(), $known));
    }

    /**
     * @return list<string>
     */
    public function toList(): array
    {
        return array_keys($this->keys);
    }
}
