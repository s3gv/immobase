<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Shared\Figure;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Welche Zahlen ein Modul auf die Uebersicht stellt.
 *
 * Dieselbe Bauart wie bei {@see \App\Shared\Todo\ContributesTodos} und
 * {@see \App\Shared\Search\SearchesRecords}: das Modul meldet sich an, die
 * Uebersicht fragt, wer sich gemeldet hat. **Kein Modul haengt vom Dashboard
 * ab.**
 *
 * **Jede Quelle prueft ihr eigenes Recht.** Eine Zahl ist eine Auskunft —
 * „offene Forderungen: 48.200 €" sagt etwas ueber das Geschaeft, auch ohne
 * den dazugehoerigen Datensatz.
 *
 * **Keine teure Zahl auf Vorrat.** Was sich nicht in einer zaehlenden
 * Abfrage bestimmen laesst, merkt sich das Modul selbst — so wie es
 * `CorrectionBadge` tut — oder es liefert die Zahl gar nicht. Eine
 * Uebersicht, die bei jeder Anmeldung eine Minute rechnet, sieht niemand an.
 */
#[AutoconfigureTag('figure.source')]
interface ContributesFigures
{
    /**
     * @return list<Figure>
     */
    public function figures(): array;
}
