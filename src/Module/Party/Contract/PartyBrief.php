<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Party\Contract;

/**
 * Ein Stammdatensatz, so viel wie ein fremdes Modul davon sehen darf.
 *
 * Bewusst nur Primitive: wer einen Eigentuemer anzeigt, braucht Kennung,
 * Nummer und Namen — nicht die Entity mit ihren Wertobjekten und ihren
 * Regeln.
 *
 * Die Anschrift steht zweimal darin und das mit Absicht: {@see $address}
 * einzeilig fuer Listen, {@see $postalLines} zerlegt fuer das Anschriftfeld
 * eines Briefes — Zusatz, Strasse oder Postfach, Postleitzahl und Ort. Eine
 * Liste braucht eine Zeile, ein Kuvert braucht vier.
 *
 * Zerlegt wird hier und nicht beim Schreiben des Briefes: dass bei einer
 * Firma der Ansprechpartner in die Zusatzzeile gehoert, weiss dieses Modul —
 * und kein anderes soll es wissen muessen.
 */
final readonly class PartyBrief
{
    public function __construct(
        public string $id,
        public int $reference,
        public string $displayName,
        public string $address,
        /**
         * Steuernummer oder USt-IdNr. — leer, wo keine hinterlegt ist.
         *
         * Steht hier, weil die Dauermietrechnung sie braucht: nach § 14
         * Abs. 4 Nr. 2 UStG nennt eine Rechnung die Nummer des leistenden
         * Unternehmers, und das ist der Vermieter.
         */
        public string $taxNumber = '',
        /**
         * Mensch oder Firma — als Zeichenkette, damit die Entity drinnen bleibt.
         *
         * Das Mahnwesen belegt damit vor, ob der Schuldner Unternehmer ist:
         * eine Firma ist nie Verbraucher, und daran haengen neun statt fuenf
         * Prozentpunkte (§ 288 Abs. 2 BGB) und die Pauschale (Abs. 5). Ein
         * Mensch kann trotzdem Unternehmer sein — darum ist es eine
         * Vorbelegung und keine Antwort.
         */
        public string $kind = 'person',
        /** @var list<string> siehe oben */
        public array $postalLines = [],
        /** @var array{line: string, postalCode: string, city: string} die erste Anschrift in Teilen — fuer die E-Rechnung */
        public array $postal = ['line' => '', 'postalCode' => '', 'city' => ''],
    ) {
    }

    public function isACompany(): bool
    {
        return 'company' === $this->kind;
    }
}
