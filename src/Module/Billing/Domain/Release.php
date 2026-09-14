<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Billing\Domain;

use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Ob eine Abrechnung freigegeben ist — und wann.
 *
 * Zustand und Tag gehoeren zusammen und werden nur zusammen gesetzt: ein
 * freigegebener Lauf ohne Datum haette kein Briefdatum, und ein Datum ohne
 * Freigabe waere eine Angabe ohne Folge.
 *
 * Der Tag ist mehr als eine Notiz. Er steht auf jedem Schreiben, und weil ein
 * PDF spaeter erneut erzeugt wird, muss er derselbe bleiben — „heute" waere
 * morgen ein anderes Dokument.
 */
#[ORM\Embeddable]
final class Release
{
    #[ORM\Column(type: Types::STRING, length: 16, enumType: StatementStatus::class)]
    private StatementStatus $status;

    #[ORM\Column(name: 'released_on', type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?DateTimeImmutable $releasedOn;

    private function __construct(StatementStatus $status, ?DateTimeImmutable $releasedOn)
    {
        $this->status = $status;
        $this->releasedOn = $releasedOn;
    }

    public static function pending(): self
    {
        return new self(StatementStatus::Draft, null);
    }

    public function on(DateTimeImmutable $day): self
    {
        return new self(StatementStatus::Released, $day);
    }

    public function status(): StatementStatus
    {
        return $this->status;
    }

    public function isDraft(): bool
    {
        return $this->status->isDraft();
    }

    /** Das Briefdatum — null, solange nichts freigegeben ist. */
    public function day(): ?DateTimeImmutable
    {
        return $this->releasedOn;
    }
}
