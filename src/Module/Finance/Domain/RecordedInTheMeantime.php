<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Finance\Domain;

use RuntimeException;

/**
 * Jemand anders war schneller.
 *
 * Zwischen der Pruefung und dem Speichern liegt eine Luecke. Zwei
 * gleichzeitig abgeschickte Formulare sehen darin denselben Stand und legen
 * beide denselben Wert an; der eindeutige Index faengt den zweiten.
 *
 * Die Datenbank ist die letzte Linie und hat recht — nur ist ihre Meldung
 * keine Antwort, die jemand versteht. Diese hier ist eine.
 */
final class RecordedInTheMeantime extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('Inzwischen hat jemand anders gespeichert. Bitte die Seite neu laden.');
    }
}
