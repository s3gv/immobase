<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Billing\Domain;

/**
 * Was ein Lauf ergaebe — und was ihm dafuer fehlt.
 *
 * Die Vorschau zeigt beides. Solange eine Angabe fehlt, bleibt die Freigabe
 * gesperrt: eine Abrechnung, die eine Luecke stillschweigend als Null
 * verteilt, ist schlimmer als eine, die nicht herausgeht.
 */
final readonly class Proposal
{
    /**
     * @param list<ProposedDocument> $documents
     * @param list<MissingFigure>    $missing
     */
    public function __construct(
        public array $documents,
        public array $missing,
        /**
         * Die Entwicklung der Erhaltungsruecklage im Abrechnungsjahr.
         *
         * Sie gehoert zu jeder Hausgeldabrechnung und nicht zu einem
         * einzelnen Schreiben — die Ruecklage gehoert der Gemeinschaft. In
         * der Vorschau wird sie gerechnet, mit der Freigabe eingefroren.
         */
        public ?ReportedReserve $reserve = null,
    ) {
    }

    /** Der Auszug — ohne Ruecklage einer, der nichts zeigt. */
    public function reserve(): ReportedReserve
    {
        return $this->reserve ?? ReportedReserve::nothing();
    }

    public function isComplete(): bool
    {
        return [] === $this->missing;
    }

    public function isEmpty(): bool
    {
        return [] === $this->documents;
    }

    public function documentFor(string $key): ?ProposedDocument
    {
        foreach ($this->documents as $document) {
            if ($document->key() === $key) {
                return $document;
            }
        }

        return null;
    }
}
