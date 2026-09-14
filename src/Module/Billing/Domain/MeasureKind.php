<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Billing\Domain;

/**
 * Was fuer eine Massnahme geplant wird.
 *
 * Die erste Frage des Ablaufs, und die folgenreichste: an ihr haengt, **wer
 * zahlt**.
 *
 * * **Erhaltung** (§ 19 Abs. 2 Nr. 2 WEG) — Instandhaltung und
 *   Instandsetzung. Die Kosten tragen immer alle nach dem geltenden
 *   Schluessel, und dafuer ist die Erhaltungsruecklage da.
 * * **Bauliche Veraenderung** (§ 20 WEG) — alles, was ueber die Erhaltung
 *   hinausgeht: die Photovoltaikanlage, der Aufzug, der Balkon. Wer die Kosten
 *   traegt, entscheidet § 21 WEG, und das haengt an der Mehrheit.
 * * **Privilegierte Massnahme** (§ 20 Abs. 2 WEG) — Barrierefreiheit,
 *   E-Mobilitaet, Einbruchschutz, Glasfaser. Sie kann jeder Eigentuemer
 *   verlangen; die Kosten traegt dann, wer sie verlangt hat (§ 21 Abs. 1).
 */
enum MeasureKind: string
{
    case Maintenance = 'maintenance';
    case Structural = 'structural';
    case Privileged = 'privileged';

    public function labelKey(): string
    {
        return 'billing.budget.measure.'.$this->value;
    }

    /** Haengt die Kostentragung am Abstimmungsergebnis? */
    public function asksAboutTheVote(): bool
    {
        return self::Structural === $this;
    }

    /** Traegt die Erhaltungsruecklage diese Massnahme? */
    public function mayUseTheReserve(): bool
    {
        return self::Maintenance === $this;
    }
}
