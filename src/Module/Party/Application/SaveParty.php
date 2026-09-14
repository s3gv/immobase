<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Party\Application;

use App\Module\Party\Domain\Party;
use App\Module\Party\Domain\PartyRepository;
use App\Module\Party\Domain\TaxId;

/**
 * Legt einen Stammdatensatz an oder uebernimmt Aenderungen an einem
 * vorhandenen.
 *
 * Die Referenznummer wird erst hier vergeben — ein abgebrochener Ablauf soll
 * keine Luecke in der Nummernfolge hinterlassen. Sie kommt aus einer Sequenz,
 * damit zwei gleichzeitige Abläufe nicht dieselbe bekommen; scheitert das
 * Speichern danach doch, bleibt die gezogene Nummer ungenutzt.
 */
final readonly class SaveParty
{
    public function __construct(private PartyRepository $parties)
    {
    }

    public function __invoke(PartyDraft $draft, ?Party $existing): Party
    {
        if (null === $existing) {
            $party = $draft->toParty($this->parties->nextReference());
            $party->taxedAs(TaxId::of($draft->taxNumber()));
        } else {
            $party = $existing;
            PartyValues::apply($party, $draft);
        }

        $this->parties->save($party);

        return $party;
    }
}
