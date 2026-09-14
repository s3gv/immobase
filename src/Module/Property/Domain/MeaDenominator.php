<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Property\Domain;

/**
 * Der Nenner, auf den sich alle Anteile eines Objekts beziehen.
 *
 * Er steht am Objekt und nicht an der Einheit: Anteile lassen sich nur
 * vergleichen und aufsummieren, wenn sie dieselbe Skala haben. In der
 * Teilungserklaerung steht ueblicherweise eine dieser drei.
 *
 * Ohne labelKey(): eine 1000 heisst in jeder Sprache 1000.
 */
enum MeaDenominator: int
{
    case Hundred = 100;
    case Thousand = 1000;
    case TenThousand = 10000;
}
