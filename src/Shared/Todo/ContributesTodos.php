<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Shared\Todo;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Was ein Modul auf die Uebersicht meldet.
 *
 * Jedes Modul meldet sich selbst an, wie bei
 * {@see \App\Shared\Search\SearchesRecords}. Die Uebersicht zaehlt
 * keine Module auf; sie fragt, wer sich gemeldet hat.
 *
 * Die Schnittstelle liegt in Shared: **kein Modul haengt vom Dashboard ab.**
 *
 * **Jede Quelle prueft ihr eigenes Recht** und liefert sonst nichts. Wer das
 * Mahnwesen nicht sehen darf, soll auch nicht erfahren, dass dort etwas offen
 * ist — die Zahl allein ist schon eine Auskunft.
 *
 * **Keine zweite Wahrheit.** Eine Quelle liest denselben Dienst wie das
 * Abzeichen am Menuepunkt. Eine Kachel, die eine andere Zahl nennt als der
 * Punkt daneben, ist schlimmer als keine.
 */
#[AutoconfigureTag('todo.source')]
interface ContributesTodos
{
    /**
     * @return list<Todo>
     */
    public function todos(): array;
}
