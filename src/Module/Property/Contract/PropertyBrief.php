<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Property\Contract;

/**
 * Ein Objekt, so viel wie ein fremdes Modul davon sehen darf.
 *
 * Nummer und Name — genug fuer eine Auswahlliste und einen Verweis.
 *
 * Die Anschrift steht zweimal darin: {@see $address} einzeilig fuer Betreff
 * und Kopfzeile, {@see $postalLines} zerlegt fuer das Anschriftfeld — Strasse,
 * dann Postleitzahl und Ort. Gebraucht wird das, wenn die Gemeinschaft selbst
 * Empfaengerin oder Glaeubigerin eines Schreibens ist.
 *
 * Das Konto steht mit Inhaber und IBAN darin, weil auf einer Dauermietrechnung
 * der Zahlungsempfaenger steht — eine Mietrechnung ohne Konto ist keine. Die
 * Glaeubiger-ID nur, wenn jemand davon Lastschriften einzieht.
 */
final readonly class PropertyBrief
{
    public function __construct(
        public string $id,
        public int $number,
        public string $name,
        /** Fuehrt dieses Objekt eine Erhaltungsruecklage? Nur bei WEG. */
        public bool $keepsAReserve = false,
        /** Einzeilig, fuer Betreff und Kopfzeile eines Schreibens. */
        public string $address = '',
        /**
         * Tag und Monat, an denen das Wirtschaftsjahr beginnt.
         *
         * Nicht das Jahr selbst: die Regel steht am Objekt und gilt fuer
         * jedes Jahr. Wer abrechnet, setzt sie mit der Jahreszahl zusammen.
         */
        public int $fiscalYearDay = 1,
        public int $fiscalYearMonth = 1,
        /** Wird hier WEG verwaltet? Dann gibt es Hausgeldabrechnungen. */
        public bool $managesWeg = false,
        public string $payeeName = '',
        public string $payeeIban = '',
        public string $creditorId = '',
        /** @var list<string> siehe oben */
        public array $postalLines = [],
    ) {
    }

    /** „20001 · Rosenweg 12–14" */
    public function oneLine(): string
    {
        return $this->number.' · '.$this->name;
    }
}
