<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Auth\Domain;

use App\Shared\Text\Trimmed;

/**
 * Wonach die Benutzerliste eingeschraenkt wird.
 *
 * Ohne Zutun steht der laufende Stand in der Liste: eingeladene und aktive
 * Konten. Eine offene Einladung ist keine Vergangenheit, sondern Arbeit, die
 * noch aussteht — sie bleibt sichtbar. Deaktivierte sind erledigt und stehen
 * bereit, wenn jemand sie sucht.
 *
 * Das Statusfeld bleibt daneben stehen: „eingeladen" und „deaktiviert" sind
 * hier wirklich verschiedene Fragen, und wer die Seite oeffnet, sucht oft
 * genau die eine. Ein gewaehlter Status schlaegt den Schalter.
 */
final readonly class UserFilter
{
    private function __construct(
        public ?string $search,
        public ?UserStatus $status,
        public bool $administratorsOnly,
        public bool $withPast,
    ) {
    }

    public static function none(): self
    {
        return new self(null, null, false, false);
    }

    public static function of(
        ?string $search,
        ?string $status,
        bool $administratorsOnly = false,
        bool $withPast = false,
    ): self {
        $chosen = self::statusOrNull($status);

        return new self(
            self::searchOrNull($search),
            $chosen,
            $administratorsOnly,
            $withPast || UserStatus::Deactivated === $chosen,
        );
    }

    public function isEmpty(): bool
    {
        return null === $this->search
            && null === $this->status
            && !$this->administratorsOnly
            && !$this->withPast;
    }

    /**
     * Ein unbekannter Statusname aus der Adresszeile schraenkt nicht ein. Er
     * ist Eingabe, kein Programmierfehler — dieselbe Regel wie ueberall sonst.
     */
    private static function statusOrNull(?string $status): ?UserStatus
    {
        $trimmed = Trimmed::orNull($status);

        return null === $trimmed || 'all' === $trimmed ? null : UserStatus::tryFrom($trimmed);
    }

    private static function searchOrNull(?string $search): ?string
    {
        $trimmed = Trimmed::orNull($search);

        return null === $trimmed ? null : mb_strtolower($trimmed);
    }
}
