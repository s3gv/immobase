<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Portal\Domain;

use DateTimeImmutable;

/**
 * Vorschlaege — und die eine Entscheidung, die es je Vorschlag gibt.
 *
 * Eigen neben {@see EnquiryRepository}, obwohl ein Vorschlag an einer Anfrage
 * haengt: hier geht es nicht ums Finden, sondern um einen Uebergang, den
 * genau einer gewinnen darf.
 */
interface ProposalRepository
{
    /**
     * Mehrere Schreibvorgaenge als einer — und ein Weg zurueck.
     *
     * Ueber einen Vorschlag zu entscheiden heisst: ihn abhaken, die Aenderung
     * uebernehmen und es dem sagen, der gefragt hat. Bliebe davon die Haelfte
     * stehen, stuende ein Vorschlag als uebernommen da, ohne dass sich etwas
     * geaendert haette — oder umgekehrt.
     *
     * **Das Zuruecknehmen laeuft ueber den Rueckgabewert und nicht ueber eine
     * Ausnahme.** Eine Absage ist hier der gewoehnliche Fall — der Datensatz
     * ist weg, jemand war schneller —, und eine Ausnahme durch die
     * Transaktion schloesse den Entity Manager: der Rest der Anfrage haette
     * dann keinen mehr.
     *
     * @param callable(): bool $work false heisst: alles zuruecknehmen
     *
     * @return bool was der Aufruf zurueckgab
     */
    public function atomically(callable $work): bool;

    /**
     * Den Vorschlag entscheiden — genau einmal, auch bei zwei gleichzeitigen
     * Klicks.
     *
     * Der Uebergang laeuft als bedingte Anweisung in der Datenbank: nur, wer
     * ihn noch offen vorfindet, bekommt ihn. Die Pruefung an der Entity
     * allein genuegt nicht — zwei Anfragen laden beide denselben offenen
     * Vorschlag, beide kommen durch, und beide schreiben.
     *
     * @return bool false heisst: jemand anders war schneller
     */
    /**
     * Wie viele Vorschlaege noch auf eine Entscheidung warten.
     *
     * Entschieden wird einmal — bis dahin wartet der, der gefragt hat. Ohne
     * diese Zahl erfuehre davon nur, wer zufaellig die Anfrage oeffnet.
     */
    public function countOpen(): int;

    public function decideOnce(
        Proposal $proposal,
        ProposalDecision $decision,
        string $userId,
        DateTimeImmutable $at,
    ): bool;
}
