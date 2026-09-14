<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Party\Contract;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Was mit einem Stammdatensatz verschwindet.
 *
 * Geschwister zu {@see PartyLinkSource}, mit derselben Richtung und demselben
 * Muster — der Unterschied ist die Folge, nicht die Form: **was verknuepft
 * ist, haelt das Loeschen auf; was zugehoerig ist, wird mitgenommen.**
 *
 * Eine Einheit haelt auf. Sie gehoert der Partei nicht, sie verweist auf sie,
 * und ohne den Verweis waere sie kaputt. Ein Gespraech im Portal gehoert ihr:
 * ein Gespraech ohne Gegenueber ist Aktenmuell.
 *
 * Die Reihenfolge steht fest und ist die halbe Zusicherung: erst fragen, was
 * haengt — haengt etwas, wird gar nicht geloescht —, dann das Zugehoerige
 * raeumen, dann die Partei. Alles in einer Transaktion.
 */
#[AutoconfigureTag('party.belongings')]
interface PartyBelongings
{
    /**
     * @param list<string> $partyIds
     */
    public function discardFor(array $partyIds): void;

    /**
     * Was dabei verschwindet — in fertigen Saetzen.
     *
     * Damit die Rueckfrage es benennen kann. Ein laufendes Gespraech
     * stillschweigend wegzuraeumen, auf dessen Antwort jemand wartet, ist
     * genau die Sorte Ueberraschung, die man einer Verwaltung nicht zumutet.
     *
     * Fertig uebersetzt und nicht als Schluessel: die Saetze tragen Zahlen
     * („3 Anfragen"), und ein Schluessel ohne seine Zahl waere nur die halbe
     * Auskunft.
     *
     * @param list<string> $partyIds
     *
     * @return array<string, list<string>> Kennung der Partei auf ihre Ankuendigungen
     */
    public function announceFor(array $partyIds): array;
}
