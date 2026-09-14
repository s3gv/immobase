<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Billing\Domain;

/**
 * Die Nummer, unter der ein Schreiben angesprochen wird.
 *
 * `<Art>-<Objektnummer>/<Einheitennummer>-<Jahr>-<Abrechnungs-ID>-<Iteration>`,
 * zum Beispiel `NK-20001/1-2025-7-1`.
 *
 * Jedes Schreiben traegt seine eigene: wer anruft, nennt eine Nummer, und
 * eine, die der ganze Lauf teilt, sagt nicht, um welche Wohnung es geht.
 *
 * Die Art steht vorn, weil sie sonst fehlte: bei Sondereigentumsverwaltung
 * bekommen der Eigentuemer und sein Mieter im selben Lauf je ein Schreiben
 * zur selben Einheit. Ohne `HG`/`NK` trueegen beide dieselbe Nummer. Der
 * Wirtschaftsplan traegt `WP` und reiht sich damit ein.
 *
 * Das Kuerzel kommt als Zeichenkette herein und nicht als Aufzaehlung: die
 * Referenz beschreibt, wie eine Nummer aussieht, und nicht, welche Schreiben
 * es gibt. Wer sie baut, weiss, was er schreibt.
 *
 * Bei einer Korrektur bleibt alles gleich bis auf die Iteration. Damit steht
 * auf beiden Schreiben erkennbar dieselbe Abrechnung — die Korrektur ist eine
 * Ergaenzung und kein neuer Vorgang.
 *
 * Zusammengesetzt und nicht gespeichert: die Teile stehen ohnehin einzeln da,
 * und zwei Wahrheiten laufen auseinander.
 */
final readonly class Reference
{
    public function __construct(
        /** `HG`, `NK` oder `WP` — deutsch und unuebersetzt. */
        private string $kind,
        private int $propertyNumber,
        private int $unitNumber,
        private int $fiscalYear,
        private int $number,
        private int $iteration,
    ) {
    }

    /** Die Iteration davor — die, die eine Korrektur berichtigt. Keine vor der ersten. */
    public function previous(): ?self
    {
        return $this->iteration > 1
            ? new self($this->kind, $this->propertyNumber, $this->unitNumber, $this->fiscalYear, $this->number, $this->iteration - 1)
            : null;
    }

    public function toString(): string
    {
        return \sprintf(
            '%s-%d/%d-%d-%d-%d',
            $this->kind,
            $this->propertyNumber,
            $this->unitNumber,
            $this->fiscalYear,
            $this->number,
            $this->iteration,
        );
    }
}
