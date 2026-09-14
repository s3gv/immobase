<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Billing\Domain;

/**
 * Was auf den Blaettern steht — als eine Zeichenkette.
 *
 * Nie gespeichert, nie angezeigt. Sie beantwortet eine Frage: **saehe das
 * Schreiben heute anders aus als das, das herausgegangen ist?**
 *
 * {@see PlanContents} fragt etwas anderes, naemlich ob jemand den Plan
 * angefasst hat. Das ist nicht dasselbe: Betraege, Verteilungswerte und
 * Empfaenger haengen an Angaben, die ausserhalb des Plans stehen — Flaeche,
 * Miteigentumsanteil, fester Anteil, erfasster Verbrauch, wer die Einheit
 * besitzt und wo er wohnt. Wer dort etwas berichtigt, aendert am Plan nichts
 * und am Blatt alles.
 *
 * Verglichen wird darum das Ergebnis und nicht die Eingabe: die eingefrorene
 * Vorlage gegen das, was heute herauskaeme.
 */
final class LetterContents
{
    private function __construct()
    {
    }

    /**
     * @param list<PlannedDocument> $documents
     */
    public static function of(array $documents): string
    {
        return implode('|', array_map(self::letterOf(...), $documents));
    }

    private static function letterOf(PlannedDocument $document): string
    {
        $parts = [
            $document->unitId,
            // Nummer und Bezeichnung stehen im Betreff des Schreibens und in
            // der Einheitenwahl. Eine umbenannte Einheit ist damit ein
            // anderes Blatt, auch wenn keine Zahl sich ruehrt.
            (string) $document->unitNumber,
            $document->unitLabel,
            $document->recipientLabel,
            $document->recipientAddress,
            (string) $document->advance()->cents(),
        ];

        foreach ($document->lines as $line) {
            $parts[] = self::lineOf($line);
        }

        return implode('~', $parts);
    }

    private static function lineOf(PlannedLine $line): string
    {
        $distribution = $line->distribution;

        return implode(';', [
            $line->kind->value,
            $line->costKind,
            $line->reason,
            (string) $line->previous->cents(),
            (string) $line->total->cents(),
            (string) $line->amount->cents(),
            $distribution->key(),
            $distribution->explanation(),
            $distribution->shareOf(),
            $distribution->shareTotal(),
            $distribution->measure() ?? '',
        ]);
    }
}
