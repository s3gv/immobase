<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Party\Contract;

/**
 * Ein Stammdatensatz mit allem, was der Betroffene selbst sehen darf.
 *
 * Eigen neben {@see PartyBrief}, weil es eine andere Frage beantwortet: der
 * Kurzform genuegt „wer ist das" fuer eine Liste oder einen Briefkopf, hier
 * geht es darum, **was ueber mich gespeichert ist**. Das Portal zeigt es
 * seinem Eigentuemer, und dafuer gehoeren alle Anschriften und alle
 * Kontaktwege dazu, nicht nur die erste.
 *
 * Die Kurzform dafuer zu erweitern waere der falsche Weg gewesen: sie wird an
 * einem halben Dutzend Stellen benutzt, die davon nichts brauchen.
 */
final readonly class PartyDetails
{
    /**
     * @param list<string> $addresses jede Anschrift einzeilig samt Zusatz, die erste zuerst
     * @param list<string> $emails
     * @param list<string> $phones
     */
    public function __construct(
        public string $id,
        public int $reference,
        public string $displayName,
        /** „person" oder „company" — als Zeichenkette, damit die Entity drinnen bleibt. */
        public string $kind,
        public array $addresses,
        public array $emails,
        public array $phones,
        public string $taxNumber,
    ) {
    }
}
