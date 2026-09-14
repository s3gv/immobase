<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Dunning\Domain;

use App\Shared\Ui\Page;

/**
 * Die Forderungen — gespeichert und wiedergefunden.
 */
interface ClaimRepository
{
    public function save(Claim $claim): void;

    public function remove(Claim $claim): void;

    public function byId(string $id): ?Claim;

    /**
     * Die Forderung zu dieser Vorauszahlung — falls schon eine angelegt ist.
     *
     * Die Frage, die verhindert, dass dieselbe Rate zweimal gemahnt wird.
     */
    public function forAdvance(string $paymentId): ?Claim;

    /**
     * Zu welchen Vorauszahlungen es schon eine Forderung gibt.
     *
     * Eine Abfrage statt einer je Zeile: die Uebersicht vergleicht die
     * ueberfaelligen Zahlungen mit dieser Liste, und bei zweihundert
     * Wohnungen waeren das sonst zweihundert Abfragen.
     *
     * @return list<string> Kennungen der Vorauszahlungen
     */
    public function advancesWithAClaim(): array;

    /**
     * Die offenen Forderungen eines Schuldners bei einem Glaeubiger.
     *
     * Die Buendelung des Schreibens: ein Schreiben, ein Glaeubiger — und der
     * ist vollstaendig gemeint ({@see CreditorIdentity}). Die Gemeinschaft
     * Rosenweg 12 ist nicht die Gemeinschaft Lindenallee 8, und wer zwei
     * Wohnungen im selben Haus von zwei Eigentuemern mietet, bekommt zwei
     * Schreiben.
     *
     * @return list<Claim>
     */
    public function openFor(string $debtorPartyId, CreditorIdentity $creditor): array;

    /** @return list<Claim> alle offenen, aeltester Verzug zuerst */
    public function allOpen(): array;

    public function countMatching(ClaimFilter $filter): int;

    /**
     * @return list<Claim>
     */
    public function matching(ClaimFilter $filter, Page $page): array;
}
