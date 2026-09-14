<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Settings\Contract;

/**
 * Wer hier schreibt.
 *
 * Die Angaben der Hausverwaltung, so viel wie ein fremdes Modul davon sehen
 * darf: bewusst nur Primitive. Wer einen Briefkopf setzt, soll nicht
 * vierzehn Schluesselkonstanten kennen und selbst zusammenraten muessen, was
 * vollstaendig ist.
 *
 * Alles ist freiwillig. Eine frische Installation hat nichts davon, und das
 * ist kein Fehlerfall — nur ein Briefkopf, der noch nichts sagt.
 */
final readonly class Organisation
{
    public function __construct(
        public string $name,
        public string $street,
        public string $postalCode,
        public string $city,
        public string $phone,
        public string $email,
        public string $website,
        public string $iban,
        public string $bic,
        public string $accountHolder,
        /** Inhaber oder Geschaeftsfuehrung — fuer den Briefkopf. */
        public string $management,
        public string $registerCourt,
        public string $registerNumber,
        public string $vatId,
    ) {
    }

    /** Genug fuer einen Absender. */
    public function hasAddress(): bool
    {
        return '' !== $this->street && '' !== $this->postalCode && '' !== $this->city;
    }

    /**
     * Genug, um zu sagen, wohin gezahlt wird.
     *
     * Die BIC entscheidet nicht mit: bei SEPA im Inland ist sie entbehrlich.
     */
    public function hasBank(): bool
    {
        return '' !== $this->iban;
    }
}
