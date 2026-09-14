<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Finance\Domain;

use App\Shared\Text\Trimmed;

/**
 * Wonach die Darlehensliste eingeschraenkt wird.
 *
 * Zwei Fragen, und die erste ist die wichtigere: **welches Objekt?** Ein
 * Darlehen gehoert einer Gemeinschaft, und wer drei Haeuser verwaltet, will
 * die Schulden des einen sehen und nicht die Summe aller.
 *
 * Die Suche trifft die Nummer, die Bezeichnung und die Bank — das sind die
 * drei Angaben, die jemand im Kopf hat, wenn er ein Darlehen sucht.
 */
final readonly class LoanFilter
{
    private function __construct(
        public ?string $propertyId,
        public ?int $number,
        public ?string $search,
    ) {
    }

    public static function none(): self
    {
        return new self(null, null, null);
    }

    public static function of(?string $propertyId, ?string $search): self
    {
        $term = Trimmed::orNull($search ?? '');

        return new self(
            Trimmed::orNull($propertyId ?? ''),
            null !== $term && ctype_digit($term) ? (int) $term : null,
            $term,
        );
    }

    public function isEmpty(): bool
    {
        return null === $this->propertyId && null === $this->search;
    }
}
