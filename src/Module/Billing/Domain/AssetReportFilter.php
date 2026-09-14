<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Billing\Domain;

use App\Shared\Text\Trimmed;

/**
 * Wonach die Liste der Vermoegensberichte eingeschraenkt wird.
 *
 * Dieselben Fragen wie bei Abrechnung und Plan — Objekt, Jahr, Zustand — und
 * dieselbe Suche ueber die Bezeichnung. Was in der Adresszeile steht, ist
 * Eingabe: eine unbekannte Angabe schraenkt nicht ein, statt zu scheitern.
 */
final readonly class AssetReportFilter
{
    private function __construct(
        public ?int $propertyNumber,
        public ?int $fiscalYear,
        public ?StatementStatus $status,
        public ?string $search,
    ) {
    }

    public static function none(): self
    {
        return new self(null, null, null, null);
    }

    /** Nur die angefangene Arbeit — fuer die Kennzahl auf der Uebersicht. */
    public static function draftsOnly(): self
    {
        return new self(null, null, StatementStatus::Draft, null);
    }

    public static function of(?string $property, ?string $fiscalYear, ?string $status, ?string $search): self
    {
        return new self(
            self::numberOrNull($property),
            self::numberOrNull($fiscalYear),
            self::statusOrNull($status),
            self::searchOrNull($search),
        );
    }

    public function isEmpty(): bool
    {
        return null === $this->propertyNumber
            && null === $this->fiscalYear
            && null === $this->status
            && null === $this->search;
    }

    private static function numberOrNull(?string $value): ?int
    {
        $trimmed = Trimmed::orNull($value ?? '');

        return null !== $trimmed && ctype_digit($trimmed) ? (int) $trimmed : null;
    }

    private static function statusOrNull(?string $value): ?StatementStatus
    {
        $trimmed = Trimmed::orNull($value ?? '');

        return null === $trimmed ? null : StatementStatus::tryFrom($trimmed);
    }

    private static function searchOrNull(?string $value): ?string
    {
        $trimmed = Trimmed::orNull($value ?? '');

        return null === $trimmed ? null : mb_strtolower($trimmed);
    }
}
