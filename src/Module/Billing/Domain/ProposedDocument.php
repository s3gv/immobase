<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Billing\Domain;

use App\Shared\Money\Money;
use DateTimeImmutable;

/**
 * Ein gerechnetes Schreiben, noch nicht eingefroren.
 *
 * Die Vorschau zeigt genau das hier, und die Freigabe schreibt genau das
 * fort. Wer prueft, prueft nicht etwas Aehnliches.
 *
 * **Mit Umsatzsteuer vermietet** (§ 9 UStG) wird netto abgerechnet: jede Zeile
 * ohne die Steuer, die in ihr steckt, darauf der Satz des Mietverhaeltnisses.
 * Die Vorauszahlungen wurden brutto gezahlt und werden brutto abgezogen — die
 * Steuer darin steht daneben, weil eine Endrechnung sie ausweisen muss (§ 14
 * Abs. 5 UStG). Ohne Umsatzsteuer bleibt alles, wie es war.
 */
final readonly class ProposedDocument
{
    /**
     * @param list<ProposedLine>    $lines
     * @param list<ProposedAdvance> $advances
     */
    public function __construct(
        public StatementKind $kind,
        public string $unitId,
        public int $unitNumber,
        public string $unitLabel,
        public DateTimeImmutable $from,
        public DateTimeImmutable $to,
        public string $recipientLabel,
        public string $recipientAddress,
        public array $lines,
        public array $advances,
        /** Das Mietverhaeltnis dahinter und ob mit Umsatzsteuer — bei der Hausgeldabrechnung keines. */
        public Letting $letting,
    ) {
    }

    /** Die Kosten — netto, wenn Umsatzsteuer darauf kommt. */
    public function costs(): Money
    {
        $total = Money::zero();

        foreach ($this->lines as $line) {
            $total = $total->plus($this->letting->isTaxed() ? $line->net() : $line->amount);
        }

        return $total;
    }

    public function tax(): Money
    {
        return $this->letting->taxation()->on($this->costs());
    }

    /** Die Steuer in den gezahlten Vorauszahlungen. */
    public function advancesTax(): Money
    {
        return $this->letting->taxation()->containedIn($this->paid());
    }

    public function paid(): Money
    {
        $total = Money::zero();

        foreach ($this->advances as $advance) {
            $total = $total->plus($advance->received);
        }

        return $total;
    }

    /** Nachzahlung, wenn positiv; Guthaben, wenn negativ. */
    public function balance(): Money
    {
        return $this->costs()->plus($this->tax())->minus($this->paid());
    }

    /** Eindeutig innerhalb eines Laufs — Einheit, Art und Zeitraum. */
    public function key(): string
    {
        return $this->unitId.'|'.$this->kind->value.'|'.$this->from->format('Y-m-d');
    }
}
