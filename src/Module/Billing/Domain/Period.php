<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Billing\Domain;

use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Der abgerechnete Zeitraum.
 *
 * Beim Eigentuemer das ganze Wirtschaftsjahr, beim Mieter der Schnitt aus
 * Mietzeit und Jahr. Er steht auf jedem Schreiben — ohne ihn ist eine
 * Abrechnung formell unvollstaendig.
 */
#[ORM\Embeddable]
final class Period
{
    #[ORM\Column(name: 'period_from', type: Types::DATE_IMMUTABLE)]
    private DateTimeImmutable $from;

    #[ORM\Column(name: 'period_to', type: Types::DATE_IMMUTABLE)]
    private DateTimeImmutable $to;

    public function __construct(DateTimeImmutable $from, DateTimeImmutable $to)
    {
        $this->from = $from;
        $this->to = $to;
    }

    public function from(): DateTimeImmutable
    {
        return $this->from;
    }

    public function to(): DateTimeImmutable
    {
        return $this->to;
    }

    /** Beide Enden zaehlen mit. */
    public function days(): int
    {
        return (int) $this->from->diff($this->to)->days + 1;
    }
}
