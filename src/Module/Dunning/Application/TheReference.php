<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Dunning\Application;

use App\Module\Dunning\Domain\CreditorIdentity;
use App\Module\Dunning\Domain\NoticeReference;
use App\Module\Dunning\Domain\NoticeRepository;
use App\Module\Party\Contract\PartyDirectory;

/**
 * `MA-98002-3` — die Nummer der Partei und das wievielte Schreiben an sie.
 *
 * Zwei Quellen kommen zusammen: die Parteinummer aus den Stammdaten und die
 * laufende Nummer aus den schon geschriebenen Briefen. Gezaehlt wird je
 * Schuldner und nicht je Glaeubiger — wer zwei Objekte in derselben
 * Verwaltung hat, bekommt keine zwei Zaehlreihen.
 */
final readonly class TheReference
{
    public function __construct(
        private NoticeRepository $notices,
        private PartyDirectory $parties,
    ) {
    }

    public function forA(string $debtorPartyId, CreditorIdentity $creditor): NoticeReference
    {
        return new NoticeReference(
            $debtorPartyId,
            $this->parties->byIds([$debtorPartyId])[$debtorPartyId]->reference ?? 0,
            $creditor,
            $this->notices->nextNumberFor($debtorPartyId),
        );
    }
}
