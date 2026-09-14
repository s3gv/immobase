<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Billing\Domain;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Die wievielte Fassung welchen Vorgangs.
 *
 * Drei Angaben, die nur zusammen etwas bedeuten: die sichtbare Nummer, die
 * Iteration darunter und der Verweis auf die Fassung davor. Eine Korrektur
 * **behaelt die Nummer** und zaehlt die Iteration hoch — damit steht auf
 * beiden Schreiben erkennbar derselbe Vorgang, und die Korrektur ist eine
 * Ergaenzung und kein neuer.
 *
 * Getrennt vom Plan, weil es eine eigene Frage ist: der Plan sagt, was
 * geplant wird, die Fassung, das wievielte Mal.
 */
#[ORM\Embeddable]
final class Edition
{
    #[ORM\Column(type: Types::INTEGER)]
    private int $number;

    #[ORM\Column(type: Types::SMALLINT)]
    private int $iteration;

    #[ORM\Column(name: 'corrects_id', type: Types::GUID, nullable: true)]
    private ?string $correctsId;

    private function __construct(int $number, int $iteration, ?string $correctsId)
    {
        $this->number = $number;
        $this->iteration = $iteration;
        $this->correctsId = $correctsId;
    }

    public static function first(int $number): self
    {
        return new self($number, 1, null);
    }

    /** Dieselbe Nummer, eine Iteration weiter — und der Verweis zurueck. */
    public function correcting(self $before, string $id): self
    {
        return new self($before->number, $before->iteration + 1, $id);
    }

    public function number(): int
    {
        return $this->number;
    }

    public function iteration(): int
    {
        return $this->iteration;
    }

    public function correctsId(): ?string
    {
        return $this->correctsId;
    }

    public function isCorrection(): bool
    {
        return null !== $this->correctsId;
    }
}
