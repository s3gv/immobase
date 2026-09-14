<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Billing\Domain;

use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Von wann bis wann eine Dauermietrechnung gilt.
 *
 * § 14 Abs. 4 Nr. 6 UStG verlangt den Zeitpunkt der Leistung; bei einem
 * Dauerschuldverhaeltnis ist das ein **Zeitraum**. Ohne ihn ist das Schreiben
 * keine Rechnung, sondern ein Brief mit Zahlen.
 *
 * Das Ende ist offen, solange die Fassung die letzte ist — auf dem Blatt
 * steht dann „bis auf Weiteres". Geschlossen wird es von der Folgefassung
 * (auf den Tag vor deren Beginn) oder vom Ende des Mietverhaeltnisses. So
 * bleibt die Kette lueckenlos: jeder Tag gehoert genau einer Fassung.
 */
#[ORM\Embeddable]
final class Validity
{
    #[ORM\Column(name: 'valid_from', type: Types::DATE_IMMUTABLE)]
    private DateTimeImmutable $from;

    #[ORM\Column(name: 'valid_until', type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?DateTimeImmutable $until;

    private function __construct(DateTimeImmutable $from, ?DateTimeImmutable $until)
    {
        $this->from = $from;
        $this->until = $until;
    }

    /** Offen — sie gilt, bis eine Folgefassung sie schliesst. */
    public static function openFrom(DateTimeImmutable $from): self
    {
        return new self($from, null);
    }

    /**
     * Dieselbe Geltung mit einem Ende.
     *
     * Ein Ende vor dem Beginn waere kein Zeitraum; es faellt auf den
     * Beginntag zurueck. Das passiert, wenn zwei Fassungen am selben Tag
     * beginnen — dann hat die vorige keinen einzigen Tag gegolten, und genau
     * das steht dann auch da.
     */
    public function endingOn(DateTimeImmutable $until): self
    {
        return new self($this->from, max($until, $this->from));
    }

    public function startingOn(DateTimeImmutable $from): self
    {
        return new self($from, $this->until);
    }

    public function from(): DateTimeImmutable
    {
        return $this->from;
    }

    public function until(): ?DateTimeImmutable
    {
        return $this->until;
    }

    public function isOpenEnded(): bool
    {
        return null === $this->until;
    }

    public function covers(DateTimeImmutable $day): bool
    {
        return $this->from <= $day && (null === $this->until || $this->until >= $day);
    }
}
