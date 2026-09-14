<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Billing\Application;

use App\Module\Billing\Domain\Plan;
use App\Module\Billing\Domain\PlanDocument;
use App\Module\Billing\Domain\PlanLine;
use App\Module\Billing\Domain\PlannedDocument;
use App\Module\Billing\Domain\PlannedLine;
use App\Module\Billing\Domain\PlanProposal;

/**
 * Aus einem Vorschlag werden Schreiben.
 *
 * Die Freigabe schreibt genau das fort, was in der Vorschau stand. Ab da ist
 * alles daran eingefroren: Name, Anschrift, Betraege, Beschriftungen,
 * Vorjahreswerte und Begruendungen. Aendert sich spaeter eine Kostenart, steht
 * auf dem zugestellten Blatt weiter der alte Name; es meldet sich nur die
 * Korrektur.
 */
final class FreezePlan
{
    private function __construct()
    {
    }

    public static function of(Plan $plan, PlanProposal $proposal): void
    {
        $plan->clearDocuments();

        foreach ($proposal->documents as $planned) {
            self::documentOf($plan, $planned);
        }
    }

    private static function documentOf(Plan $plan, PlannedDocument $planned): void
    {
        $document = new PlanDocument(
            $plan,
            $planned->unitId,
            $planned->unitNumber,
            $planned->unitLabel,
            $planned->recipientLabel,
            $planned->recipientAddress,
        );

        foreach ($planned->lines as $at => $line) {
            self::lineOf($document, $at + 1, $line);
        }
    }

    /** Die Zeile haengt sich beim Anlegen selbst an das Dokument. */
    private static function lineOf(PlanDocument $document, int $at, PlannedLine $line): void
    {
        new PlanLine(
            $document,
            $at,
            $line->kind,
            $line->costKind,
            $line->distribution,
            $line->previous,
            $line->total,
            $line->amount,
            $line->reason,
        );
    }
}
