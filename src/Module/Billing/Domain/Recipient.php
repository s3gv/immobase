<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Billing\Domain;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Wer das Schreiben bekommt — eingefroren.
 *
 * Name und Anschrift stehen fest, sobald die Abrechnung freigegeben ist. Wer
 * umzieht, bekommt seine alte Abrechnung weiter mit der alten Anschrift, unter
 * der sie ankam: das Schreiben ist ein Beleg und keine Ansicht.
 */
#[ORM\Embeddable]
final class Recipient
{
    #[ORM\Column(name: 'recipient_label', type: Types::STRING, length: 400)]
    private string $label;

    /** Mehrzeilig, wie sie ins Anschriftfeld gesetzt wird. */
    #[ORM\Column(name: 'recipient_address', type: Types::TEXT)]
    private string $address;

    public function __construct(string $label, string $address)
    {
        $this->label = $label;
        $this->address = $address;
    }

    public function label(): string
    {
        return $this->label;
    }

    public function address(): string
    {
        return $this->address;
    }
}
