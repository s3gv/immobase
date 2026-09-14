<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Property\Domain;

use RuntimeException;

/**
 * Der Nenner sollte geaendert werden, obwohl schon Anteile verteilt sind.
 *
 * Ein Wechsel von 1000 auf 10000 wuerde jeden erfassten Anteil um den Faktor
 * zehn verschieben. Ihn stillschweigend umzurechnen waere schlimmer, als ihn
 * abzulehnen: die Zahlen stehen in der Teilungserklaerung, nicht in unserer
 * Datenbank.
 */
final class MeaAlreadyDistributed extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('Der Nenner lässt sich nicht mehr ändern, es sind bereits Anteile verteilt.');
    }
}
