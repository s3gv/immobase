<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Billing\Domain;

/**
 * Was fuer eine Position des Gemeinschaftsvermoegens.
 *
 * Drei Arten, und alle drei werden **erfasst**. Was die Anwendung selbst
 * weiss — den Ruecklagenstand und die offenen Hausgelder — steht nicht hier:
 * eine erfassbare Forderung waere eine zweite Wahrheit neben den
 * Vorauszahlungen, und sie liefe von der ersten weg.
 *
 * * **Guthaben** ist Geld auf einem Konto. Es darf zweckgebunden sein; dann
 *   liegt dort die Erhaltungsruecklage, und der Bericht zaehlt sie nicht
 *   zusaetzlich.
 * * **Verbindlichkeit** ist die offene Handwerkerrechnung, der Abschlag beim
 *   Versorger, die Verwaltervergütung des Dezembers.
 * * **Gegenstand** ist alles Uebrige: der Heizoelvorrat, die Gartengeraete,
 *   ein Anspruch gegen einen Dritten. Er darf ohne Wert dastehen.
 */
enum AssetKind: string
{
    case Bank = 'bank';
    case Liability = 'liability';
    case Holding = 'holding';

    public function labelKey(): string
    {
        return 'billing.report.kind.'.$this->value;
    }

    /**
     * Braucht sie einen Betrag?
     *
     * Ein Gegenstand darf ohne einen dastehen: „Gartengeraete" gehoert in die
     * Aufstellung, auch wenn niemand sie bewertet hat. Ein Konto ohne Stand
     * dagegen ist keine Auskunft, sondern eine Luecke.
     */
    public function needsAnAmount(): bool
    {
        return self::Holding !== $this;
    }

    /** Verbindlichkeiten mindern das Vermoegen. */
    public function reducesTheAssets(): bool
    {
        return self::Liability === $this;
    }

    /** Nur Guthaben kann zweckgebunden sein — auf einem Gartengeraet liegt keine Ruecklage. */
    public function mayBeEarmarked(): bool
    {
        return self::Bank === $this;
    }
}
