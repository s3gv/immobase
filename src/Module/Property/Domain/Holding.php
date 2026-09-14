<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Property\Domain;

use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use InvalidArgumentException;

/**
 * Von wann bis wann jemandem eine Einheit gehoert.
 *
 * Beide Enden duerfen offen sein, und beide bedeuten etwas anderes als
 * „unbekannt": **ohne Anfang** heisst, dass diese Partei die Einheit schon
 * hielt, als die Verwaltung sie uebernahm — der Kaufvertrag liegt Jahrzehnte
 * zurueck und niemand wird ihn fuer eine Abrechnung heraussuchen. **Ohne
 * Ende** heisst, dass ihr die Einheit noch gehoert. Das ist der Normalfall,
 * und deshalb kostet er keine Eingabe.
 *
 * Ein Verkauf ist zwei Angaben: beim bisherigen Eigentuemer ein Ende, beim
 * neuen ein Anfang. Der neue beginnt am Tag nach dem Ende — nicht am selben,
 * sonst gehoerte die Wohnung einen Tag lang zweien.
 */
#[ORM\Embeddable]
final class Holding
{
    #[ORM\Column(name: 'owned_from', type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?DateTimeImmutable $from;

    #[ORM\Column(name: 'owned_to', type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?DateTimeImmutable $to;

    private function __construct(?DateTimeImmutable $from, ?DateTimeImmutable $to)
    {
        $this->from = $from;
        $this->to = $to;
    }

    /** Gehoert schon immer und noch — der Normalfall. */
    public static function always(): self
    {
        return new self(null, null);
    }

    /**
     * @throws InvalidArgumentException
     */
    public static function of(?DateTimeImmutable $from, ?DateTimeImmutable $to): self
    {
        if (null !== $from && null !== $to && $to < $from) {
            throw new InvalidArgumentException('Das Eigentum kann nicht enden, bevor es beginnt.');
        }

        return new self($from, $to);
    }

    public function from(): ?DateTimeImmutable
    {
        return $this->from;
    }

    public function to(): ?DateTimeImmutable
    {
        return $this->to;
    }

    /** Gehoerte die Einheit an diesem Tag dieser Partei? */
    public function covers(DateTimeImmutable $day): bool
    {
        return (null === $this->from || $day >= $this->from)
            && (null === $this->to || $day <= $this->to);
    }

    /** Beruehrt dieses Eigentum den Zeitraum ueberhaupt? */
    public function touches(DateTimeImmutable $from, DateTimeImmutable $to): bool
    {
        return (null === $this->to || $this->to >= $from)
            && (null === $this->from || $this->from <= $to);
    }

    public function isOpenEnded(): bool
    {
        return null === $this->to;
    }
}
