<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Property\Contract;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Wer auf eine Einheit verweist.
 *
 * Jedes Modul, das Einheiten benutzt — Miete, spaeter Abrechnung und Zaehler —
 * liefert eine Umsetzung und meldet damit, was an einer Einheit haengt. Das
 * Objektmodul fragt danach, bevor es loeschen laesst, und zeigt die Verweise
 * auf der Einheitenseite an.
 *
 * Die Richtung ist Absicht: Property darf die anderen Module nicht kennen, sie
 * kennen aber Property. Deshalb liegt die Schnittstelle hier und wird dort
 * umgesetzt.
 *
 * Solange es keine Umsetzung gibt, hat keine Einheit Verweise und darf
 * geloescht werden.
 */
#[AutoconfigureTag('property.unit_link_source')]
interface UnitLinkSource
{
    /**
     * Was an diesen Einheiten haengt.
     *
     * @param list<string> $unitIds
     *
     * @return array<string, list<UnitLink>> Kennung der Einheit auf ihre Verweise
     */
    public function linksTo(array $unitIds): array;
}
