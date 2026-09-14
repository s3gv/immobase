<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Billing\Domain;

/**
 * Wo ein Vorgang steht, ueber den die Versammlung beschliesst.
 *
 * Drei Zustaende, weil ein solcher Vorgang einen Umweg ueber die Versammlung
 * nimmt, den eine Abrechnung nicht kennt:
 *
 * * **Entwurf** — die Zahlen entstehen. Verwerfbar, aenderbar, ohne Wirkung.
 * * **Vorlage** — die Zahlen sind herausgegeben, damit die Eigentuemer sie vor
 *   der Versammlung lesen koennen. Noch immer aenderbar: die Versammlung darf
 *   anders beschliessen, und dann ist die Vorlage eben ueberholt.
 * * **Beschlossen** — die Vorschuesse gelten. Ab da ist alles eingefroren, und
 *   was daran falsch ist, wird korrigiert und nicht ueberschrieben.
 *
 * Der Unterschied zwischen den ersten beiden ist keine Zierde: wer ein
 * Hausgeld bestreitet, fragt, was ihm wann vorgelegen hat.
 *
 * Dieselben drei Zustaende beim Wirtschaftsplan und beim Budgetplan. Zwei
 * Aufzaehlungen dafuer waeren zwei Wahrheiten darueber, was „Vorlage" heisst.
 */
enum ResolutionStatus: string
{
    case Draft = 'draft';
    case Proposed = 'proposed';
    case Released = 'released';

    public function labelKey(): string
    {
        return 'billing.stage.'.$this->value;
    }

    /** Laesst sich hier noch etwas aendern? */
    public function isOpen(): bool
    {
        return self::Released !== $this;
    }
}
