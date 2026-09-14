<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Billing\Domain;

use App\Shared\Money\Money;

/**
 * Eine gerechnete Zeile eines Einzelwirtschaftsplans.
 *
 * Dieselben Angaben wie {@see PlanLine} — die Vorschau zeigt sie, die Freigabe
 * macht daraus die Zeile, und das Schreiben wird aus ihr gesetzt. Ein
 * eingefrorenes Dokument gibt sie ueber {@see PlanDocument::asPlanned()} wieder
 * her: so gibt es einen Weg zum Blatt und nicht zwei, die auseinanderlaufen.
 *
 * Vorjahreswert und Begruendung stehen mit auf der Zeile und nicht nur im
 * Entwurf. Ein Wirtschaftsplan muss „nach Grund und Hoehe nachpruefbar" sein,
 * und beides ist genau das: die Hoehe im Vergleich und der Grund daneben.
 *
 * `costKind` ist bei der Ruecklage leer — sie ist keine Kostenart, und ihr
 * Name ist Wortschatz der Anwendung und keine erfasste Angabe. Wer sie
 * beschriftet, nimmt {@see PlanLineKind::labelKey()}.
 */
final readonly class PlannedLine
{
    public function __construct(
        public PlanLineKind $kind,
        public string $costKind,
        public Distribution $distribution,
        /** Was diese Position im Vorjahr tatsaechlich gekostet hat. */
        public Money $previous,
        public Money $total,
        public Money $amount,
        /** Warum geplant wurde, was geplant wurde — oft leer. */
        public string $reason,
    ) {
    }

    public function isReserve(): bool
    {
        return $this->kind->isReserve();
    }

    /** Nichts geplant — die Zeile steht da, damit niemand sie sucht. */
    public function isNothing(): bool
    {
        return $this->total->isZero();
    }

    /** Die Veraenderung gegenueber dem Vorjahr. */
    public function change(): Money
    {
        return $this->total->minus($this->previous);
    }
}
