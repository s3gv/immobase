<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Billing\Domain;

/**
 * Hat sich die Grundlage geaendert, seit die Vorlage herausging?
 *
 * Die Vorlage selbst aendert sich nicht mehr — das ist ihr Sinn. Aber die
 * Angaben, aus denen sie entstand, koennen sich sehr wohl aendern: ein
 * berichtigter Miteigentumsanteil, ein nachgetragener Verbrauch, eine
 * umbenannte Einheit, ein Eigentuemerwechsel. Dann laege der Versammlung
 * etwas vor, das nicht mehr stimmt.
 *
 * Die Frage wird an zwei Stellen gestellt und darf nur eine Antwort haben:
 * die Vorlage-Seite meldet sie, und die Freigabe verweigert sich ohne
 * ausdrueckliche Bestaetigung. Ein Hinweis, den man nie sehen muss, weil man
 * einen Schritt ueberspringen kann, ist keiner.
 */
final class PlanDrift
{
    private function __construct()
    {
    }

    /**
     * Die herausgegebene Vorlage, aus ihren eingefrorenen Schreiben.
     *
     * Ohne Luecken, denn eine Vorlage mit Luecken gibt es nicht — geprueft hat
     * das die Herausgabe, bevor sie einfror.
     */
    public static function asIssued(Plan $plan): ?PlanProposal
    {
        if (!$plan->stage()->wasProposed()) {
            return null;
        }

        return new PlanProposal(array_map(
            static fn (PlanDocument $document): PlannedDocument => $document->asPlanned(),
            $plan->documents(),
        ), []);
    }

    /** Saehe das Blatt heute anders aus als das, das herausging? */
    public static function between(Plan $plan, PlanProposal $today): bool
    {
        $issued = self::asIssued($plan);

        return null !== $issued
            && LetterContents::of($issued->documents) !== LetterContents::of($today->documents);
    }
}
