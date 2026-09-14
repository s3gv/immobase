<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Portal\UserInterface\Controller;

use App\Module\Portal\Application\DecideAChange;
use App\Module\Portal\Domain\Enquiry;

/**
 * Was die Freigabe zu sehen bekommt.
 *
 * Der Vorschlag, der heutige Stand und die Felder, die sich seit dem
 * Vorschlagen geaendert haben. Die dritte Angabe ist die, auf die es
 * ankommt: hat jemand im Verwalterbereich dasselbe Feld angefasst, waehrend
 * der Vorschlag lag, soll das dastehen, **bevor** es ueberschrieben wird.
 */
final readonly class ChangeReview
{
    public function __construct(private DecideAChange $decisions)
    {
    }

    /**
     * @return array<string, mixed>
     */
    public function of(Enquiry $enquiry): array
    {
        $proposal = $enquiry->proposal();

        return [
            'proposal' => $proposal,
            'todaysValues' => null === $proposal ? [] : $this->decisions->todaysValues($proposal),
            'drifted' => null === $proposal ? [] : $this->decisions->changedMeanwhile($proposal),
        ];
    }
}
