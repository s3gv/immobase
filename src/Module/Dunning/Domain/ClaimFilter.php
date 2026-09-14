<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Dunning\Domain;

use App\Shared\Text\Trimmed;

/**
 * Wonach die Liste der Forderungen eingeschraenkt wird.
 *
 * Objekt und Zustand — und kein Jahr: ein Rueckstand aus dem Vorjahr ist
 * heute immer noch einer, und nach einem Jahr zu filtern versteckte genau
 * die aeltesten.
 *
 * Der Zustand ist nicht der der Forderung, sondern **der der Arbeit**: offen
 * und noch nichts unternommen, gemahnt und Frist laeuft, Frist abgelaufen,
 * erledigt. Danach fragt jemand, der wissen will, was zu tun ist.
 */
final readonly class ClaimFilter
{
    public const string OPEN = 'open';
    public const string DUE = 'due';
    public const string RUNNING = 'running';
    public const string SETTLED = 'settled';

    private function __construct(
        public ?string $propertyId,
        public ?string $state,
        public ?string $search,
    ) {
    }

    public static function none(): self
    {
        return new self(null, null, null);
    }

    public static function of(?string $propertyId, ?string $state, ?string $search): self
    {
        $known = \in_array($state, [self::OPEN, self::DUE, self::RUNNING, self::SETTLED], true);

        return new self(
            Trimmed::orNull($propertyId ?? ''),
            $known ? $state : null,
            Trimmed::orNull($search ?? ''),
        );
    }

    public function isEmpty(): bool
    {
        return null === $this->propertyId && null === $this->state && null === $this->search;
    }

    /** @return list<string> */
    public static function states(): array
    {
        return [self::OPEN, self::DUE, self::RUNNING, self::SETTLED];
    }
}
