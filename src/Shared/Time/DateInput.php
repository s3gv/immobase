<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Shared\Time;

use DateTimeImmutable;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\Request;

/**
 * Ein Datum aus einem Formularfeld — oder nichts.
 *
 * Die Felder sind `<input type="date">`; der Browser schickt ISO, und darauf
 * wird streng geparst. Streng heisst: danach gegengelesen. `new
 * DateTimeImmutable()` rechnet ueberlaufende Angaben stillschweigend um — aus
 * dem 30. Februar wird der 2. Maerz, aus dem 32. Januar der 1. Februar. Das
 * faellt niemandem auf, am wenigsten dem, der es getippt hat. Wer den Tag
 * nicht wiedererkennt, den er eingegeben hat, soll eine Meldung bekommen und
 * nicht ein anderes Datum.
 *
 * Das Ausrufezeichen im Format setzt Uhrzeit und Zeitzone zurueck: ein
 * Mietbeginn gilt ab einem Tag und nicht ab einem Moment.
 */
final class DateInput
{
    private const string ISO = '!Y-m-d';

    private function __construct()
    {
    }

    /**
     * @throws InvalidArgumentException
     */
    public static function orNull(Request $request, string $field): ?DateTimeImmutable
    {
        $raw = trim($request->request->getString($field));

        if ('' === $raw) {
            return null;
        }

        $date = DateTimeImmutable::createFromFormat(self::ISO, $raw);

        if (false === $date || $date->format('Y-m-d') !== $raw) {
            throw new InvalidArgumentException(\sprintf('„%s" ist kein Datum.', $raw));
        }

        return $date;
    }
}
