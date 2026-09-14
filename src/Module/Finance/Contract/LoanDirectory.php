<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Finance\Contract;

use DateTimeImmutable;

/**
 * Die Darlehen eines Objekts, fuer die Abrechnung.
 *
 * Zwei Fragen, und beide werden **gerechnet**: der Wirtschaftsplan fragt, was
 * ein Jahr an Zins und Tilgung kostet, der Vermoegensbericht, was am Stichtag
 * noch offen ist. Beide Antworten stehen im Tilgungsplan; hier stehen sie
 * summiert je Objekt, damit das abrechnende Modul nicht selbst durch die
 * Darlehen laufen muss.
 *
 * Nichts davon ist gespeichert. Eine gepflegte Restschuld waere die zweite
 * Wahrheit neben der, die sich rechnen laesst.
 */
interface LoanDirectory
{
    /** Was die Darlehen des Objekts in diesem Kalenderjahr kosten. */
    public function burdenIn(string $propertyId, int $year): LoanBurden;

    /**
     * Die Darlehen, die am Ende dieses Tages noch offen sind.
     *
     * Einzeln und nicht als Summe: der Vermoegensbericht stellt auf, und
     * „Darlehen: 173.000 Euro" waere eine Zahl, aus der niemand herauslesen
     * kann, ob es eines ist oder drei. Abgeloeste stehen nicht dabei.
     *
     * @return list<OpenLoan>
     */
    public function outstandingAt(string $propertyId, DateTimeImmutable $day): array;
}
