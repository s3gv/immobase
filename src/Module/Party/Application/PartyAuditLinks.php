<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Party\Application;

use App\Module\Party\Domain\PartyRepository;
use App\Shared\Audit\LinksToRecords;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Wohin ein protokollierter Stammdatensatz fuehrt.
 *
 * Das Protokoll haelt die Kennung, die Seite braucht die Referenznummer — die
 * Uebersetzung kennt nur dieses Modul. In einem Zug fuer die ganze Seite:
 * einzeln gefragt waeren es fuenfzig Abfragen.
 */
final readonly class PartyAuditLinks implements LinksToRecords
{
    public function __construct(
        private PartyRepository $parties,
        private UrlGeneratorInterface $urls,
    ) {
    }

    public function handles(): array
    {
        return ['Party'];
    }

    public function urlsFor(string $record, array $ids): array
    {
        if ([] === $ids) {
            return [];
        }

        $urls = [];

        foreach ($this->parties->byIds($ids) as $party) {
            $urls[$party->id()] = $this->urls->generate('app_party_show', ['reference' => $party->reference()]);
        }

        return $urls;
    }
}
