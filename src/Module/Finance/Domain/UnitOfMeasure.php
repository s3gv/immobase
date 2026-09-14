<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Finance\Domain;

/**
 * Worin eine erfasste Menge gemessen ist.
 *
 * Eine Zahl ohne Einheit ist keine Menge: „84,250" ist Wasser in
 * Kubikmetern, Waerme in Kilowattstunden oder ein Zaehlerstand in Strichen,
 * und wer das spaeter liest, kann es nicht mehr entscheiden.
 *
 * Die Einheit haengt an der Kostenposition und nicht am Verteilerschluessel:
 * „Nach Verbrauch" ist ein einziger Systemschluessel, und derselbe misst
 * Wasser in m³ und Waerme in kWh. Auch nicht an der Kostenart — welche
 * Einheit auf der Rechnung steht, entscheidet der Dienstleister und nicht
 * der Katalog: Waerme kommt mal in kWh, mal in MWh, mal in GJ.
 *
 * Eine gepflegte Liste statt Freitext, aus demselben Grund wie bei den
 * Kostenarten: „m3", „m³", „cbm" und „Kubikmeter" waeren vier Einheiten,
 * und keine Abrechnung koennte sie zusammenfassen.
 */
enum UnitOfMeasure: string
{
    case CubicMetre = 'm3';
    case Litre = 'l';
    case KilowattHour = 'kwh';
    case MegawattHour = 'mwh';
    case Gigajoule = 'gj';
    /** Striche eines Heizkostenverteilers — eine Zahl ohne physikalische Groesse. */
    case HeatingUnits = 'hcu';

    /** Der lange Name, fuer die Auswahl. */
    public function labelKey(): string
    {
        return 'finance.measure.'.$this->value;
    }

    /** Das Kuerzel, das hinter der Zahl steht. */
    public function symbolKey(): string
    {
        return 'finance.measure_symbol.'.$this->value;
    }
}
