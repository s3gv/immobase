<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Finance\Domain;

use App\Module\Finance\Domain\ReserveMovement as Movement;

/**
 * Wonach die Bewegungen einer Ruecklage eingeschraenkt werden.
 *
 * Ein Ruecklagenkonto laeuft ueber Jahre und sammelt mit jeder Abrechnung
 * Zeilen an. Wer wissen will, was 2025 zugefuehrt wurde oder was eine
 * bestimmte Einheit eingezahlt hat, soll nicht scrollen muessen.
 *
 * Alle drei Angaben grenzen ein und gelten zusammen — anders als eine Suche,
 * die breit trifft: "Zufuehrung" und "2025" ist eine Und-Frage.
 *
 * Was aus der Adresszeile kommt, ist Eingabe: eine unbekannte Art oder ein
 * unsinniges Jahr schraenkt nicht ein, statt einen Fehler zu erzeugen.
 */
final readonly class ReserveFilter
{
    private function __construct(
        public ?ReserveMovementKind $kind,
        public ?int $year,
        public ?string $unitId,
    ) {
    }

    public static function none(): self
    {
        return new self(null, null, null);
    }

    public static function of(string $kind, ?int $year, string $unitId): self
    {
        return new self(
            '' === trim($kind) ? null : ReserveMovementKind::tryFrom(trim($kind)),
            null === $year || $year < 1000 || $year > 9999 ? null : $year,
            '' === trim($unitId) ? null : trim($unitId),
        );
    }

    public function isEmpty(): bool
    {
        return null === $this->kind && null === $this->year && null === $this->unitId;
    }

    public function matches(Movement $movement): bool
    {
        return (null === $this->kind || $this->kind === $movement->kind())
            && (null === $this->year || $this->year === (int) $movement->occurredOn()->format('Y'))
            && (null === $this->unitId || $this->unitId === $movement->unitId());
    }
}
