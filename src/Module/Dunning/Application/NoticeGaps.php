<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Dunning\Application;

use App\Module\Dunning\Domain\BaseRates;
use App\Module\Dunning\Domain\Notice;
use App\Module\Dunning\Domain\Recipients;
use DateTimeImmutable;

/**
 * Was die Ausstellung aufhaelt.
 *
 * Die wichtigste Luecke ist der **Basiszinssatz**: fehlt er fuer das
 * laufende Halbjahr, rechnete die Anwendung mit einem veralteten Satz
 * weiter. Das faellt nicht auf — es faellt vor Gericht auf.
 *
 * Die uebrigen sind die eines jeden Briefes: ohne Anschrift kommt er nicht
 * an, ohne Konto weiss der Schuldner nicht, wohin, und ohne Forderung hat er
 * nichts zu zahlen.
 */
final readonly class NoticeGaps
{
    /**
     * @return list<string> Uebersetzungsschluessel, leer heisst vollstaendig
     */
    public static function of(
        Notice $notice,
        BaseRates $rates,
        Recipients $recipients,
        DateTimeImmutable $on,
    ): array {
        return array_values(array_filter([
            $rates->missingFor($on) ? 'dunning.missing.base_rate' : null,
            [] === $notice->lines() ? 'dunning.missing.claims' : null,
            '' === $recipients->debtorAddress() ? 'dunning.missing.debtor_address' : null,
            '' === $recipients->creditorAddress() ? 'dunning.missing.creditor_address' : null,
            '' === $recipients->payeeIban() ? 'dunning.missing.payee' : null,
        ], static fn (?string $key): bool => null !== $key));
    }
}
