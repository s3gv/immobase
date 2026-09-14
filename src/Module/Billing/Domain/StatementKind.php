<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Billing\Domain;

/**
 * Welche Abrechnung jemand bekommt.
 *
 * Zwei Arten, zwei Empfaengerkreise, zwei Verteilungen — und zwei
 * Rechtsgrundlagen:
 *
 * * **Hausgeldabrechnung** an den Eigentuemer (§ 28 Abs. 2 WEG). Sie enthaelt
 *   alles, auch was den Mieter nichts angeht: Verwaltervergütung, Zufuehrung
 *   zur Erhaltungsruecklage, Kontofuehrung.
 * * **Nebenkostenabrechnung** an den Mieter (§ 556 BGB, § 2 BetrKV). Nur
 *   umlagefaehige Betriebskosten, und ein anderer Verteilerschluessel — in
 *   der WEG die Miteigentumsanteile, im Mietvertrag meist die Flaeche.
 *
 * Eine Einheit kann beides ausloesen: bei Sondereigentumsverwaltung bekommt
 * der Eigentuemer die eine und sein Mieter die andere.
 */
enum StatementKind: string
{
    case HouseMoney = 'house_money';
    case OperatingCosts = 'operating_costs';

    public function labelKey(): string
    {
        return 'billing.kind.'.$this->value;
    }

    /**
     * Das Kuerzel, das der Referenznummer vorangeht.
     *
     * Deutsch und nicht uebersetzt: es ist Teil einer Nummer, unter der ein
     * Schreiben angesprochen wird. Wer anruft, liest vor, was auf dem Blatt
     * steht — und dieselbe Abrechnung darf nicht je nach Spracheinstellung
     * des Sachbearbeiters anders heissen.
     */
    public function shortName(): string
    {
        return match ($this) {
            self::HouseMoney => 'HG',
            self::OperatingCosts => 'NK',
        };
    }

    /** Zaehlen nur umlagefaehige Kosten? */
    public function apportionableOnly(): bool
    {
        return self::OperatingCosts === $this;
    }
}
