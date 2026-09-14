<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Property\Contract;

/**
 * Eine Einheit, so viel wie ein fremdes Modul davon sehen darf.
 *
 * Bewusst nur Primitive: wer eine Einheit auswaehlt oder anzeigt, braucht
 * Kennung, Nummern, Bezeichnung und Anschrift — nicht die Entity mit ihren
 * Anteilen, Eigentuemern und Regeln.
 *
 * Das Objekt steht mit dabei, weil eine Einheit ohne ihr Gebaeude nichts
 * bedeutet: „WE 1, EG links" gibt es in jedem zweiten Haus.
 *
 * Flaeche, Miteigentumsanteil und Nutzungsart sind angehaengt und nicht
 * mitten hineingesetzt: die Abrechnung braucht sie fuer ihre
 * Verteilerschluessel, jeder bisherige Aufrufer kommt ohne sie aus.
 *
 * Bei der Flaeche heisst null nicht „null Quadratmeter", sondern „nicht
 * erfasst" — eine Verteilung, die das verwechselt, verschenkt einen Anteil,
 * ohne dass es jemandem auffaellt.
 *
 * Der Miteigentumsanteil steht als **Zahl** da und nicht als Bruch: wer damit
 * verteilt, rechnet damit. Der Nenner des Objekts gehoert nicht hierher, denn
 * verteilt wird ueber die Summe der Anteile aller Einheiten, und die steht
 * nirgends an einer einzelnen. „250/1000" war hier einmal der Wert, und keine
 * Abrechnung nach Miteigentumsanteilen kam damit durch.
 */
final readonly class UnitBrief
{
    public function __construct(
        public string $id,
        public int $number,
        public string $label,
        /**
         * Die Kennung des Objekts, nicht nur seine Nummer.
         *
         * Fremde Module halten Verweise als Kennung — eine Kostenposition
         * kennt ihr Objekt als UUID. Ohne sie liesse sich nicht pruefen, ob
         * eine Einheit zu ihm gehoert, ohne dafuer noch einmal das Objekt
         * nachzuschlagen.
         */
        public string $propertyId,
        public int $propertyNumber,
        public string $propertyName,
        public string $address,
        /** Wohnflaeche in Quadratmetern; null heisst „nicht erfasst". */
        public ?string $area = null,
        /** Der Zaehler des Miteigentumsanteils — „250", nicht „250/1000". */
        public string $mea = '0',
        /** Wohnen, Gewerbe, Stellplatz, Sonstiges — als Zeichenkette. */
        public string $usage = 'residential',
    ) {
    }

    /** „20001 · Rosenweg 12–14 · WE 1, EG links" */
    public function oneLine(): string
    {
        return $this->propertyNumber.' · '.$this->propertyName.' · '.$this->label;
    }
}
