<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Billing\Domain;

use App\Shared\Money\Money;

/**
 * Was ein Wirtschaftsplan ergaebe — und was ihm dafuer fehlt.
 *
 * Dieselbe Regel wie bei der Abrechnung: solange eine Angabe fehlt, bleibt die
 * Freigabe gesperrt. Ein Plan, der eine Luecke stillschweigend als Null
 * verteilt, laesst die anderen Eigentuemer zahlen, ohne dass es jemandem
 * auffaellt — und das ein ganzes Jahr lang.
 */
final readonly class PlanProposal
{
    /**
     * @param list<PlannedDocument> $documents
     * @param list<MissingFigure>   $missing
     */
    public function __construct(
        public array $documents,
        public array $missing,
    ) {
    }

    public function isComplete(): bool
    {
        return [] === $this->missing;
    }

    public function isEmpty(): bool
    {
        return [] === $this->documents;
    }

    public function documentFor(string $key): ?PlannedDocument
    {
        foreach ($this->documents as $document) {
            if ($document->key() === $key) {
                return $document;
            }
        }

        return null;
    }

    /** Die geplanten Kosten aller Einheiten — der Gesamtwirtschaftsplan. */
    public function costs(): Money
    {
        $total = Money::zero();

        foreach ($this->documents as $document) {
            $total = $total->plus($document->costs());
        }

        return $total;
    }

    /** Die beschlossene Zufuehrung zur Erhaltungsruecklage. */
    public function reserve(): Money
    {
        $total = Money::zero();

        foreach ($this->documents as $document) {
            $total = $total->plus($document->reserve());
        }

        return $total;
    }

    /** Was die Gemeinschaft im Planjahr aufbringt. */
    public function yearly(): Money
    {
        return $this->costs()->plus($this->reserve());
    }
}
