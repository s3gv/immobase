<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Party\UserInterface\Controller;

use App\Shared\Flow\FlowDefinition;
use App\Shared\Flow\FlowStep;

/**
 * Woraus der Ablauf besteht.
 *
 * Anlegen und Bearbeiten laufen durch dieselben Schritte. Die Kennung
 * unterscheidet sie, damit zwei gleichzeitig offene Vorgaenge sich nicht den
 * Zwischenstand in der Sitzung teilen.
 */
final class PartyFlow
{
    private function __construct()
    {
    }

    public static function definition(string $id): FlowDefinition
    {
        return new FlowDefinition('party.'.$id, [
            new FlowStep('kind', 'party.step.kind.label', 'party.step.kind.explanation'),
            new FlowStep('name', 'party.step.name.label', 'party.step.name.explanation'),
            new FlowStep('address', 'party.step.address.label', 'party.step.address.explanation'),
            new FlowStep('contact', 'party.step.contact.label', 'party.step.contact.explanation'),
            new FlowStep('note', 'party.step.note.label', 'party.step.note.explanation'),
            new FlowStep('review', 'party.step.review.label', 'party.step.review.explanation'),
        ]);
    }
}
