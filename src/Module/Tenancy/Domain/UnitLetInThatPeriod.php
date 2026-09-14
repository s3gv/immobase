<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Tenancy\Domain;

use RuntimeException;

/**
 * Zwei Mietverhaeltnisse an derselben Einheit im selben Zeitraum.
 *
 * Der eindeutige Teilindex schuetzt nur die Gegenwart — zwei *aktive* an einer
 * Einheit sind unmoeglich. Zwei beendete mit ueberlappenden Zeitraeumen waeren
 * es nicht, und tagesgenau gerechnet zahlten dann zwei Parteien denselben Tag.
 *
 * `whileSaving` entscheidet, was die Oberflaeche damit macht: gemeldet von der
 * Datenbank heisst, der Entity Manager ist geschlossen, und dann fuehrt nur
 * noch Weiterleiten zu einer heilen Seite.
 */
final class UnitLetInThatPeriod extends RuntimeException
{
    private function __construct(string $message, public readonly bool $whileSaving)
    {
        parent::__construct($message);
    }

    public static function of(int $number): self
    {
        return new self(
            \sprintf('Die Einheit ist in diesem Zeitraum bereits an Mietverhältnis %d vermietet.', $number),
            false,
        );
    }

    public static function inTheMeantime(): self
    {
        return new self('Die Einheit wurde inzwischen für diesen Zeitraum vermietet.', true);
    }
}
