<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Property\Contract;

/**
 * Die Objekte, fuer fremde Module.
 *
 * Heute nur fuer eine Auswahlliste: „zeige mir die Mietverhaeltnisse dieses
 * Hauses". Deshalb reicht die ganze Liste — eine Verwaltung hat Dutzende
 * Objekte, keine Zehntausende. Waechst das, wird aus der Liste ein Picker,
 * und dann kommt hier eine Suche dazu.
 */
interface PropertyDirectory
{
    /**
     * Alle Objekte, nach Nummer.
     *
     * @return list<PropertyBrief>
     */
    public function all(): array;

    /**
     * Die Objekte zu diesen Kennungen.
     *
     * Fuer Module, die Objektkennungen halten und sie anzeigen wollen — die
     * Finanzen etwa. Gefragt wird fuer eine ganze Seite auf einmal, nicht je
     * Zeile.
     *
     * @param list<string> $ids
     *
     * @return array<string, PropertyBrief> Kennung auf Kurzform
     */
    public function byIds(array $ids): array;
}
