<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Shared\Change;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Ein Datensatz, an dem sich etwas vorschlagen laesst.
 *
 * **Liegt in Shared und nicht im Portal.** Umgesetzt wird die Schnittstelle
 * von den besitzenden Modulen — den Stammdaten, den Objekten —, und stuende
 * sie im Portal, haetten sie eine Abhaengigkeit dorthin. Kein Modul haengt
 * vom Portal ab; derselbe Grund, aus dem {@see \App\Shared\Pdf\SenderOfLetters}
 * hier gelandet ist.
 *
 * **Welche Felder offenstehen, sagt das besitzende Modul.** Nicht eine Liste
 * im Portal: so kann kein Feld auftauchen, das die Stammdaten gar nicht
 * aendern lassen wuerden — auch nicht ueber ein umgebogenes Formular, denn
 * was `fieldsOf()` nicht nennt, wird verworfen.
 *
 * Ein neues Ziel spaeter — eine Bankverbindung etwa — ist eine Umsetzung
 * mehr und keine Zeile im Portal.
 */
#[AutoconfigureTag('shared.changeable')]
interface RecordThatMayChange
{
    public function kind(): RecordKind;

    /**
     * Die aenderbaren Felder mit ihrem heutigen Wert.
     *
     * Leer heisst: an diesem Datensatz gibt es nichts vorzuschlagen — etwa,
     * weil es ihn nicht gibt.
     *
     * @return list<ChangeableField>
     */
    public function fieldsOf(string $id): array;

    /**
     * Was gegen die vorgeschlagenen Werte spricht — je Feld.
     *
     * Leer heisst annehmbar. Die Einwaende sind Uebersetzungsschluessel, denn
     * gelesen werden sie im Verwalterbereich.
     *
     * @param array<string, string> $values
     *
     * @return array<string, string>
     */
    public function objectionsTo(string $id, array $values): array;

    /**
     * Uebernehmen — erst aufrufen, wenn {@see objectionsTo()} leer war.
     *
     * @param array<string, string> $values
     */
    public function apply(string $id, array $values): void;
}
