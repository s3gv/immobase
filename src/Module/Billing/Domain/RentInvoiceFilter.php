<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Billing\Domain;

use App\Shared\Text\Trimmed;

/**
 * Wonach die Liste der Dauermietrechnungen eingeschraenkt wird.
 *
 * Objekt und Zustand wie ueberall — und **kein Jahr**: eine Dauermietrechnung
 * gehoert keinem Wirtschaftsjahr an, sie gilt, bis sich etwas aendert. Nach
 * einem Jahr zu filtern hiesse, nach etwas zu fragen, das es hier nicht gibt.
 *
 * Die Suche trifft die Nummer des Mietverhaeltnisses, die Bezeichnung und den
 * Namen des Mieters, wie er auf dem Schreiben steht.
 */
final readonly class RentInvoiceFilter
{
    private function __construct(
        public ?string $propertyId,
        public ?StatementStatus $status,
        public ?int $number,
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
        return new self(null, StatementStatus::Draft, null, null);
    }

    public static function of(?string $propertyId, ?string $status, ?string $search): self
    {
        $term = Trimmed::orNull($search ?? '');

        return new self(
            Trimmed::orNull($propertyId ?? ''),
            StatementStatus::tryFrom(Trimmed::orNull($status ?? '') ?? ''),
            null !== $term && ctype_digit($term) ? (int) $term : null,
            $term,
        );
    }

    public function isEmpty(): bool
    {
        return null === $this->propertyId && null === $this->status && null === $this->search;
    }
}
