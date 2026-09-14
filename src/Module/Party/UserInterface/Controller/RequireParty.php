<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Party\UserInterface\Controller;

use App\Module\Party\Domain\Party;
use App\Module\Party\Domain\PartyRepository;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Holt einen Stammdatensatz zur Referenznummer oder beendet die Anfrage.
 *
 * Die Nummer steht in der Adresse und ist damit Eingabe. Jede Seite, die
 * einen Datensatz zeigt, braucht dieselbe Antwort auf eine Nummer, die es
 * nicht gibt — deshalb steht sie einmal hier und nicht in jedem Controller.
 */
final readonly class RequireParty
{
    public function __construct(private PartyRepository $parties)
    {
    }

    public function __invoke(int $reference): Party
    {
        return $this->parties->byReference($reference)
            ?? throw new NotFoundHttpException('Diesen Stammdatensatz gibt es nicht.');
    }
}
