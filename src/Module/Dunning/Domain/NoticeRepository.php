<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Dunning\Domain;

/**
 * Die Mahnschreiben — gespeichert und wiedergefunden.
 */
interface NoticeRepository
{
    public function save(Notice $notice): void;

    public function remove(Notice $notice): void;

    public function byId(string $id): ?Notice;

    /**
     * Mehrere Schreibvorgaenge als einer.
     *
     * Ausstellen heisst: das Schreiben festhalten und die Forderungen
     * fortschreiben. Bliebe davon die Haelfte stehen, stuende ein Schreiben
     * in der Welt, von dem die Forderung nichts weiss.
     *
     * @template T
     *
     * @param callable(): T $work
     *
     * @return T
     */
    public function atomically(callable $work): mixed;

    /** Die naechste Nummer fuer diesen Schuldner, ab eins. */
    public function nextNumberFor(string $debtorPartyId): int;

    /**
     * Alle Schreiben, die eine dieser Forderungen betreffen.
     *
     * @param list<string> $claimIds
     *
     * @return list<Notice> das juengste zuerst
     */
    public function forClaims(array $claimIds): array;

    /**
     * Das zuletzt ausgestellte Schreiben an diesen Schuldner von diesem Glaeubiger.
     *
     * Es sagt, welche Stufe als Naechstes kommt und wann die Frist ablief —
     * und darum zaehlt der ganze Glaeubiger mit: eine Erinnerung fuer den
     * einen macht aus dem naechsten Schreiben fuer den anderen keine erste
     * Mahnung.
     */
    public function lastIssuedFor(string $debtorPartyId, CreditorIdentity $creditor): ?Notice;

    /** Der offene Entwurf dieser Paarung — es gibt hoechstens einen. */
    public function openDraftFor(string $debtorPartyId, CreditorIdentity $creditor): ?Notice;
}
