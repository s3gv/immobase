<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Auth\Contract;

/**
 * Der Portalzugang einer Partei — anlegen, ansehen, entziehen.
 *
 * Die Schnittstelle liegt hier und nicht im Portal, weil die Anmeldung die
 * Konten fuehrt. Und sie spricht ueber Parteikennungen als Zeichenketten:
 * `Auth` soll `Party` nicht kennen muessen, um jemanden anzumelden, und
 * `Party` nicht `Auth\Domain\User`, um einen Zugang einzurichten.
 *
 * **Freigeschaltet wird einzeln.** Wer hundert Einladungen auf einmal
 * verschickt, verschickt auch hundert falsche.
 */
interface PortalAccounts
{
    public function forParty(string $partyId): ?PortalAccount;

    /**
     * Was gegen diese Adresse spricht — als Uebersetzungsschluessel.
     *
     * Null heisst: sie taugt. Gefragt wird **vor** dem Einladen, damit aus
     * einer unbrauchbaren Adresse eine Meldung im Formular wird und keine
     * Fehlerseite: `inviteFor()` wuerde an ihr scheitern, und zwar unten in
     * der Anmeldung, wo niemand mehr etwas zurueckgeben kann.
     *
     * Die Stammdaten kennen die Regeln fuer eine Anmeldeadresse nicht — sie
     * gehoeren der Anmeldung, und hier werden sie erfragt.
     */
    public function reasonAgainst(string $email): ?string;

    /**
     * Einladen — und den Link zurueckgeben.
     *
     * Der Link kommt mit, damit eine Installation ohne Mailserver ihn anzeigen
     * kann. Danach gibt es ihn nirgends mehr: gespeichert wird nur sein Hash.
     *
     * @return array{account: PortalAccount, link: string, wasSent: bool}
     */
    public function inviteFor(string $partyId, string $email): array;

    /**
     * Noch einmal einladen, mit frischem Schluessel — der alte wird entwertet.
     *
     * @return array{account: PortalAccount, link: string, wasSent: bool}
     */
    public function inviteAgain(string $partyId): array;

    /**
     * Den Zugang entziehen.
     *
     * Deaktiviert, loescht nicht: wer geloescht wird, verschwindet aus den
     * Gespraechen, an denen er beteiligt war, und ein Gespraech ohne
     * Gegenueber ist Aktenmuell.
     */
    public function revokeFor(string $partyId): void;

    /**
     * Den Zugang entfernen — mit der Partei, nicht auf Zuruf.
     *
     * Anders als {@see revokeFor()} ein echtes Loeschen. Es gibt genau einen
     * Anlass dafuer: die Partei verschwindet, und ein Konto, das fuer
     * niemanden mehr spricht, darf sich nicht anmelden koennen.
     */
    public function removeFor(string $partyId): void;
}
