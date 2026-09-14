<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Dunning\Domain;

use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Der Verzug: seit wann, und ob er noch laeuft.
 *
 * Drei Tage, die zusammengehoeren. Die **Faelligkeit** ist der Tag, an dem zu
 * zahlen war; der **Verzugsbeginn** ist der Tag danach (§ 187 Abs. 1 BGB),
 * und bei kalendermaessig bestimmter Leistung braucht es dafuer keine Mahnung
 * (§ 286 Abs. 2 Nr. 1). Der **Erledigungstag** beendet beides.
 *
 * Der Verzugsbeginn steht als eigene Angabe da und wird nicht gerechnet: bei
 * einer eingetragenen Forderung traegt ihn jemand ein, und genau das ist der
 * Mahnstart, der sonst fehlte.
 */
#[ORM\Embeddable]
final class Arrears
{
    #[ORM\Column(name: 'due_on', type: Types::DATE_IMMUTABLE)]
    private DateTimeImmutable $dueOn;

    #[ORM\Column(name: 'default_from', type: Types::DATE_IMMUTABLE)]
    private DateTimeImmutable $defaultFrom;

    #[ORM\Column(name: 'settled_on', type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?DateTimeImmutable $settledOn;

    private function __construct(
        DateTimeImmutable $dueOn,
        DateTimeImmutable $defaultFrom,
        ?DateTimeImmutable $settledOn,
    ) {
        $this->dueOn = $dueOn;
        $this->defaultFrom = $defaultFrom;
        $this->settledOn = $settledOn;
    }

    public static function of(DateTimeImmutable $dueOn, DateTimeImmutable $defaultFrom): self
    {
        return new self($dueOn, $defaultFrom, null);
    }

    /** Der Regelfall: der Verzug beginnt am Tag nach der Faelligkeit. */
    public static function after(DateTimeImmutable $dueOn): self
    {
        return new self($dueOn, $dueOn->modify('+1 day'), null);
    }

    public function dueOn(): DateTimeImmutable
    {
        return $this->dueOn;
    }

    public function beginsOn(): DateTimeImmutable
    {
        return $this->defaultFrom;
    }

    public function settledOn(): ?DateTimeImmutable
    {
        return $this->settledOn;
    }

    public function isOpen(): bool
    {
        return null === $this->settledOn;
    }

    public function endingOn(DateTimeImmutable $day): self
    {
        return new self($this->dueOn, $this->defaultFrom, $day);
    }

    /** Wie viele Tage der Verzug an diesem Stichtag schon laeuft. */
    public function daysOn(DateTimeImmutable $day): int
    {
        $until = $this->settledOn ?? $day;

        return $until <= $this->defaultFrom ? 0 : (int) $this->defaultFrom->diff($until)->days;
    }
}
