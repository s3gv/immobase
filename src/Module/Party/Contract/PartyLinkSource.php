<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Party\Contract;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Wer auf einen Stammdatensatz verweist.
 *
 * Jedes Modul, das Stammdaten benutzt — Objekte, Vertraege, Abrechnungen —
 * liefert eine Umsetzung und meldet damit, was an einem Datensatz haengt.
 * Party fragt danach, bevor es loeschen laesst.
 *
 * Die Richtung ist Absicht: Party darf die anderen Module nicht kennen, sie
 * kennen aber Party. Deshalb liegt die Schnittstelle hier und wird dort
 * umgesetzt.
 *
 * Solange es keine Umsetzung gibt, hat kein Datensatz Verknuepfungen und darf
 * geloescht werden.
 */
#[AutoconfigureTag('party.link_source')]
interface PartyLinkSource
{
    /**
     * Was an diesen Datensaetzen haengt, als Uebersetzungsschluessel.
     *
     * Bewusst eine Menge und nicht ein einzelner Datensatz: die Uebersicht
     * fragt fuer eine ganze Seite auf einmal. Eine Schnittstelle, die nur
     * einzeln antwortet, zwingt jeden Aufrufer zu fuenfzig Abfragen.
     *
     * @param list<string> $partyIds
     *
     * @return array<string, list<string>> Kennung des Datensatzes auf seine Verknuepfungen
     */
    public function linksTo(array $partyIds): array;
}
