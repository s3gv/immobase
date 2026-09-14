<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Party\Domain;

use App\Shared\Text\Trimmed;

/**
 * Wonach die Stammdatenliste eingeschraenkt wird.
 *
 * Eine leere Angabe schraenkt nicht ein. Das klingt selbstverstaendlich, ist
 * es aber nicht: eine Suche nach dem leeren Text wuerde sonst nichts finden,
 * obwohl der Nutzer gar nichts gesucht hat.
 */
final readonly class PartyFilter
{
    private function __construct(
        public ?PartyRole $role,
        public ?string $search,
        public bool $withPast,
    ) {
    }

    public static function none(): self
    {
        return new self(null, null, false);
    }

    /**
     * Ohne Zutun stehen nur aktive Datensaetze in der Liste.
     *
     * Archivierte sind aus dem Weg geraeumt worden; sie ungefragt wieder
     * hineinzuholen machte das Archivieren wirkungslos. Der Schalter holt sie
     * zurueck, wenn jemand sie sucht.
     */
    public static function of(?string $role, ?string $search, bool $withPast = false): self
    {
        return new self(self::roleOrNull($role), self::searchOrNull($search), $withPast);
    }

    public function isEmpty(): bool
    {
        return null === $this->role && null === $this->search && !$this->withPast;
    }

    /**
     * Ein unbekannter Rollenname aus der Adresszeile schraenkt nicht ein,
     * statt einen Fehler zu erzeugen. Er ist Eingabe, kein Programmierfehler
     * — dieselbe Regel wie beim Schrittnamen im Multi-Step-Baustein.
     */
    private static function roleOrNull(?string $role): ?PartyRole
    {
        $trimmed = Trimmed::orNull($role);

        return null === $trimmed ? null : PartyRole::tryFrom($trimmed);
    }

    private static function searchOrNull(?string $search): ?string
    {
        $trimmed = Trimmed::orNull($search);

        return null === $trimmed ? null : mb_strtolower($trimmed);
    }
}
