<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Audit\Domain;

/**
 * Wer gehandelt hat — die Verwaltung, jemand aus dem Portal, oder niemand.
 *
 * Die Unterscheidung zaehlt: eine Anmeldung aus dem Portal ist ein Mieter
 * oder Eigentuemer an seinen eigenen Daten, eine aus der Verwaltung ist
 * jemand an allen. In einer Liste, die beides zeigt, muss man das sehen.
 *
 * `Unknown` ist die gescheiterte Anmeldung: dort gibt es nur eine getippte
 * Adresse und kein Konto — und ob es zu ihr eines gibt, sagt das Protokoll
 * nicht, denn das waere die Auskunft, nach der ein Angreifer sucht.
 */
enum ActorKind: string
{
    case Staff = 'staff';
    case Portal = 'portal';
    case System = 'system';
    case Unknown = 'unknown';

    public function labelKey(): string
    {
        return 'audit.actor.'.$this->value;
    }
}
