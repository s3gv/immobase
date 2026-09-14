<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Finance\Domain;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Worin die Mengen einer Kostenposition gemessen sind.
 *
 * Leer, solange nicht nach Verbrauch verteilt wird — dann gibt es keine
 * Mengen. Siehe {@see UnitOfMeasure} dazu, warum die Einheit an der Position
 * haengt und nicht am Verteilerschluessel.
 */
#[ORM\Embeddable]
final class Metering
{
    #[ORM\Column(name: 'measure', type: Types::STRING, length: 8, nullable: true, enumType: UnitOfMeasure::class)]
    private ?UnitOfMeasure $measure;

    public function __construct(?UnitOfMeasure $measure = null)
    {
        $this->measure = $measure;
    }

    public function measure(): ?UnitOfMeasure
    {
        return $this->measure;
    }

    /** Wo nichts gemessen wird, bleibt auch keine Einheit stehen. */
    public function in(?UnitOfMeasure $measure, bool $isMetered): self
    {
        return new self($isMetered ? $measure : null);
    }
}
