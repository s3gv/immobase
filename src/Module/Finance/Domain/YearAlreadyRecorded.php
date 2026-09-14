<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Finance\Domain;

use RuntimeException;

/**
 * Zu einem Wirtschaftsjahr gibt es hoechstens einen Wert.
 *
 * Zwei waeren nicht entscheidbar: welcher gilt fuer die Abrechnung? Wer
 * einen zweiten Betrag hat, hat entweder eine zweite Position oder eine
 * Korrektur — und beides sagt man besser.
 */
final class YearAlreadyRecorded extends RuntimeException
{
    /**
     * Zwei gleichzeitig abgeschickte Formulare.
     *
     * Dann hat nicht der Benutzer den Wert zweimal erfasst, sondern zwei
     * Anfragen haben denselben Stand gelesen — die Datenbank hat es
     * gemerkt, und die Meldung sagt das auch.
     */
    public static function inTheMeantime(): self
    {
        return new self('Für dieses Wirtschaftsjahr wurde inzwischen ein Betrag erfasst.');
    }

    public static function of(int $fiscalYear): self
    {
        return new self(\sprintf('Für das Wirtschaftsjahr %d ist schon ein Betrag erfasst.', $fiscalYear));
    }
}
