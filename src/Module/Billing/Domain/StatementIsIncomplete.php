<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Billing\Domain;

use DomainException;

/**
 * Es fehlt eine Angabe, ohne die sich nicht verteilen laesst.
 *
 * Die Vorschau zeigt vorher, welche — die Freigabe ist die letzte Linie, und
 * sie greift auch dann, wenn jemand am Knopf vorbei absendet.
 */
final class StatementIsIncomplete extends DomainException
{
    public static function figuresAreMissing(): self
    {
        return new self('Es fehlen Angaben, ohne die sich nicht verteilen lässt.');
    }

    /**
     * Einem Schreiben mit Umsatzsteuer fehlt, was eine Rechnung braucht.
     *
     * @param list<string> $missing die fehlenden Angaben, schon lesbar
     */
    public static function invoiceDataIsMissing(string $document, array $missing): self
    {
        return new self(\sprintf('%s — %s', $document, implode(' ', $missing)));
    }

    public static function thereIsNothingToSend(): self
    {
        return new self('Dieser Lauf ergibt kein einziges Schreiben.');
    }
}
