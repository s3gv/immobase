<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Finance\Domain;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Wie eine Kostenposition umgelegt wird.
 *
 * Zwei Regeln, die zusammengehoeren und beide Ausnahmen kennen:
 *
 * * **Umlagefaehig** — leer heisst „wie die Kostenart". Ein Mietvertrag darf
 *   ausschliessen, was nach der BetrKV umlagefaehig waere; gesetzt heisst
 *   also „hier gilt etwas anderes als sonst".
 * * **Tagesgenau** — die Regel. Ausgeschaltet heisst „dieser Posten trifft
 *   den, der am Stichtag da war": eine Zwischenablesung, eine Nachzahlung
 *   fuer den Kabelanschluss. Die haeufige Wahl braucht keine Entscheidung,
 *   die seltene wird bewusst getroffen.
 */
#[ORM\Embeddable]
final class Apportionment
{
    #[ORM\Column(name: 'apportionable', type: Types::BOOLEAN, nullable: true)]
    private ?bool $apportionable;

    #[ORM\Column(name: 'splits_by_day', type: Types::BOOLEAN)]
    private bool $splitsByDay;

    public function __construct(?bool $apportionable = null, bool $splitsByDay = true)
    {
        $this->apportionable = $apportionable;
        $this->splitsByDay = $splitsByDay;
    }

    /** Was hier gilt — die Ausnahme, sonst die Kostenart. */
    public function isApportionable(bool $fromTheKind): bool
    {
        return $this->apportionable ?? $fromTheKind;
    }

    /** Ob hier etwas anderes gilt als bei der Kostenart. */
    public function overrides(): bool
    {
        return null !== $this->apportionable;
    }

    public function splitsByDay(): bool
    {
        return $this->splitsByDay;
    }

    public function apportionedAs(?bool $apportionable): self
    {
        return new self($apportionable, $this->splitsByDay);
    }

    public function splittingBy(bool $byDay): self
    {
        return new self($this->apportionable, $byDay);
    }
}
