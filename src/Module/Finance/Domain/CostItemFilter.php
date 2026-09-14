<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Finance\Domain;

use App\Shared\Text\Trimmed;

/**
 * Wonach die Kostenliste eingeschraenkt wird.
 *
 * Objekt, Kostenart und Umlagefaehigkeit — die drei Fragen, die ein
 * Verwalter an eine Kostenliste hat. Die Suche trifft die Nummer und die
 * Bezeichnung der Kostenart.
 *
 * Ohne Zutun zeigt die Liste den laufenden Stand. Beendete Positionen
 * stehen bereit, aber nicht im Weg — der Schalter holt sie dazu.
 */
final readonly class CostItemFilter
{
    private function __construct(
        public ?string $propertyId,
        public ?string $kindId,
        public ?bool $apportionable,
        public ?int $number,
        public ?string $search,
        public bool $withPast,
    ) {
    }

    public static function none(): self
    {
        return new self(null, null, null, null, null, false);
    }

    public static function of(
        ?string $propertyId,
        ?string $kindId,
        ?string $apportionable,
        ?string $search,
        bool $withPast = false,
    ): self {
        $term = Trimmed::orNull($search ?? '');

        return new self(
            Trimmed::orNull($propertyId ?? ''),
            Trimmed::orNull($kindId ?? ''),
            self::yesNoOrNull($apportionable),
            null !== $term && ctype_digit($term) ? (int) $term : null,
            $term,
            $withPast,
        );
    }

    public function isEmpty(): bool
    {
        return null === $this->propertyId
            && null === $this->kindId
            && null === $this->apportionable
            && null === $this->search
            && !$this->withPast;
    }

    /** Eine unbekannte Angabe schraenkt nicht ein: sie ist Eingabe. */
    private static function yesNoOrNull(?string $value): ?bool
    {
        return match (Trimmed::orNull($value ?? '')) {
            'yes' => true,
            'no' => false,
            default => null,
        };
    }
}
