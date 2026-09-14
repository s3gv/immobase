<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Property\Domain;

use App\Shared\Text\Trimmed;

/**
 * Wonach die Objektliste eingeschraenkt wird.
 *
 * Ohne Zutun steht der laufende Stand in der Liste: Entwuerfe und aktive
 * Objekte. Ein Entwurf ist angefangene Arbeit und gehoert vor Augen;
 * abgewickelte Objekte sind Geschichte und stehen bereit, wenn jemand sie
 * sucht. Ein ausdruecklich gewaehlter Status schlaegt das.
 */
final readonly class PropertyFilter
{
    private function __construct(
        public ?ManagementMode $mode,
        public ?string $search,
        public ?PropertyStatus $status,
        public bool $withPast,
    ) {
    }

    public static function none(): self
    {
        return new self(null, null, null, false);
    }

    public static function of(
        ?string $mode,
        ?string $search,
        ?string $status = null,
        bool $withPast = false,
    ): self {
        $chosen = self::statusOrNull($status);

        return new self(
            self::modeOrNull($mode),
            self::searchOrNull($search),
            $chosen,
            $withPast || true === $chosen?->isPast(),
        );
    }

    public function isEmpty(): bool
    {
        return null === $this->mode && null === $this->search && null === $this->status && !$this->withPast;
    }

    /**
     * Ein unbekannter Wert aus der Adresszeile schraenkt nicht ein, statt
     * einen Fehler zu erzeugen. Er ist Eingabe, kein Programmierfehler.
     */
    private static function modeOrNull(?string $mode): ?ManagementMode
    {
        $trimmed = Trimmed::orNull($mode);

        return null === $trimmed ? null : ManagementMode::tryFrom($trimmed);
    }

    private static function statusOrNull(?string $status): ?PropertyStatus
    {
        $trimmed = Trimmed::orNull($status);

        return null === $trimmed ? null : PropertyStatus::tryFrom($trimmed);
    }

    private static function searchOrNull(?string $search): ?string
    {
        $trimmed = Trimmed::orNull($search);

        return null === $trimmed ? null : mb_strtolower($trimmed);
    }
}
