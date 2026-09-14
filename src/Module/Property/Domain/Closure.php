<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Property\Domain;

use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Der Tag, an dem die Verwaltung endete — und warum.
 *
 * Fachlich enden nicht die Vertraege, sondern *unsere* Verwaltung davon: der
 * Mieter wohnt weiter, nur eben bei jemand anderem. Fuer die Daten ist der
 * Unterschied ohne Folge, weil beide Male zum Stichtag Schluss ist. Was
 * bleibt, ist der Vermerk — damit spaeter niemand aus dem Datensatz liest,
 * das Haus sei abgerissen worden.
 */
#[ORM\Embeddable]
final class Closure
{
    #[ORM\Column(name: 'closed_on', type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?DateTimeImmutable $on = null;

    #[ORM\Column(name: 'closed_note', type: Types::TEXT)]
    private string $note = '';

    private function __construct()
    {
    }

    public static function none(): self
    {
        return new self();
    }

    /**
     * @throws PropertyNeedsAClosingDate
     */
    public static function on(?DateTimeImmutable $day, string $note = ''): self
    {
        if (null === $day) {
            throw new PropertyNeedsAClosingDate();
        }

        $closure = new self();
        $closure->on = $day;
        $closure->note = $note;

        return $closure;
    }

    public function day(): ?DateTimeImmutable
    {
        return $this->on;
    }

    public function note(): string
    {
        return $this->note;
    }

    public function happened(): bool
    {
        return null !== $this->on;
    }
}
