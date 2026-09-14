<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Portal\Domain;

/**
 * Wie eine Anfrage steht.
 *
 * **Zwei davon werden nicht gesetzt, sie folgen.** Wer zuletzt geschrieben
 * hat, sagt, wer dran ist — ein Zustand, den jemand von Hand pflegt, steht
 * nach drei Wochen auf etwas anderem als die Wirklichkeit.
 *
 * Nur „Erledigt" ist eine Entscheidung. Und sie haelt nicht: schreibt der
 * Fragende wieder, ist die Anfrage offen. Etwas anderes waere eine Tuer, die
 * man von innen zumacht.
 */
enum EnquiryState: string
{
    case Open = 'open';
    case Answered = 'answered';
    case Done = 'done';

    public function labelKey(): string
    {
        return 'portal.state.'.$this->value;
    }

    /** Wartet auf die Verwaltung? Das zaehlt die Kachel „Offen". */
    public function isWaitingForUs(): bool
    {
        return self::Open === $this;
    }
}
