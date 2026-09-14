<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Shared\Audit;

/**
 * Was jemand getan hat.
 *
 * Nur Schreibendes und Anmeldungen. Wer etwas nur ansieht, hinterlaesst keine
 * Spur — ein Protokoll, das jeden Seitenaufruf mitschreibt, ist nach einer
 * Stunde nicht mehr zu lesen, und die Frage, die es beantworten soll, lautet
 * „wer hat das geaendert" und nicht „wer war hier".
 */
enum AuditAction: string
{
    case Created = 'created';
    case Updated = 'updated';
    case Deleted = 'deleted';
    /**
     * Rechte vergeben oder entzogen.
     *
     * Eigene Aktion und nicht „geaendert" oder „geloescht": ein vollstaendiger
     * Entzug am Datensatz „User" stuende sonst als „Benutzer geloescht" da,
     * obwohl das Konto weiterbesteht. Und eine Rechtevergabe ist ohnehin die
     * eine Aenderung, nach der im Protokoll am ehesten gefiltert wird.
     *
     * Der gespeicherte Wert ist kurz, weil die Spalte sechzehn Zeichen haelt
     * — „permissions_changed" passte nicht hinein, und eine breitere Spalte
     * waere eine Wanderung durch alle Bestaende fuer sieben Zeichen.
     */
    case PermissionsChanged = 'permissions';
    case SignedIn = 'signed_in';
    case SignedOut = 'signed_out';
    case SignInFailed = 'sign_in_failed';

    public function labelKey(): string
    {
        return 'audit.action.'.$this->value;
    }

    /** Loeschen und eine gescheiterte Anmeldung stechen heraus. */
    public function tone(): string
    {
        return match ($this) {
            self::Deleted, self::SignInFailed => 'danger',
            self::PermissionsChanged => 'warning',
            self::Created => 'success',
            default => 'neutral',
        };
    }
}
