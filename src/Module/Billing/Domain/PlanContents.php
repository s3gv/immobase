<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Billing\Domain;

/**
 * Was an einem Plan auf dem Blatt steht — als eine Zeichenkette.
 *
 * Sie wird nie gespeichert und nie angezeigt. Sie beantwortet genau eine
 * Frage: **hat sich seit vorhin etwas geaendert, das der Empfaenger sieht?**
 * Wer eine Beschlussvorlage herausgegeben hat und danach eine Zahl anfasst,
 * hat ein anderes Dokument als das, das den Eigentuemern vorlag — und der Tag
 * der Herausgabe waere dann eine falsche Auskunft.
 *
 * **Was zaehlt, steht auf dem Blatt.** Die Positionen mit Art, Schluessel,
 * Betrag und Begruendung, dazu Intervall und erster Faelligkeitstag. Was nicht
 * darauf steht, zaehlt nicht: die Bezeichnung des Laufs ist eine interne
 * Notiz, und der Beschluss erscheint auf der Vorlage gar nicht — ihn zu
 * erfassen darf sie nicht entwerten.
 *
 * Ein Fingerabdruck und kein Feld-fuer-Feld-Vergleich: so wandert jede
 * kuenftige Angabe, die aufs Blatt kommt, von selbst mit hinein — sofern sie
 * hier eingetragen wird.
 */
final class PlanContents
{
    private function __construct()
    {
    }

    public static function of(Plan $plan): string
    {
        $parts = [
            $plan->terms()->interval()->value,
            $plan->terms()->firstDueOn()->format('Y-m-d'),
        ];

        foreach ($plan->positions() as $position) {
            $parts[] = self::rowOf($position);
        }

        return implode('|', $parts);
    }

    private static function rowOf(PlanPosition $position): string
    {
        return implode('~', [
            $position->id(),
            $position->lineKind()->value,
            $position->costKindId() ?? '',
            $position->costKindLabel(),
            $position->key()->id() ?? '',
            (string) $position->entered()->cents(),
            $position->isOneOff() ? '1' : '0',
            $position->reason(),
        ]);
    }
}
