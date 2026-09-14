<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Tenancy\Contract;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Wer auf ein Mietverhaeltnis verweist.
 *
 * Heute niemand: Zahlungen, Sollstellungen und Abrechnungen kommen erst. Die
 * Schnittstelle steht trotzdem schon da, weil die Regel sonst keinen Ort
 * haette — geloescht wird nur, solange nichts daran haengt, sonst deaktiviert.
 * Ohne Quelle ist die Antwort leer und alles loeschbar; das ist heute richtig
 * und aendert sich beim ersten Zahlungseingang von selbst.
 *
 * Die Richtung ist Absicht, wie bei PartyLinkSource: dieses Modul darf die
 * spaeteren nicht kennen, sie kennen aber dieses.
 */
#[AutoconfigureTag('tenancy.link_source')]
interface TenancyLinkSource
{
    /**
     * Was an diesen Mietverhaeltnissen haengt, als Uebersetzungsschluessel.
     *
     * @param list<string> $tenancyIds
     *
     * @return array<string, list<string>>
     */
    public function linksTo(array $tenancyIds): array;
}
