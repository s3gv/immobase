<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Property\Domain;

use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Ob wir diese Einheit noch verwalten — und seit wann nicht mehr.
 *
 * Dasselbe Paar wie am Objekt und aus demselben Grund zusammengefasst: der
 * Zustand ohne Stichtag waere fuer die Abrechnung wertlos. Ein eigener Typ,
 * weil die Einheit keinen Entwurf kennt: sie entsteht im Objektablauf und ist
 * mit dem Anlegen fertig.
 */
#[ORM\Embeddable]
final class UnitManagement
{
    #[ORM\Column(name: 'status', type: Types::STRING, length: 16, enumType: UnitStatus::class)]
    private UnitStatus $status;

    #[ORM\Embedded(class: Closure::class, columnPrefix: false)]
    private Closure $closure;

    private function __construct(UnitStatus $status, Closure $closure)
    {
        $this->status = $status;
        $this->closure = $closure;
    }

    public static function active(): self
    {
        return new self(UnitStatus::Active, Closure::none());
    }

    public function status(): UnitStatus
    {
        return $this->status;
    }

    public function closure(): Closure
    {
        return $this->closure;
    }

    /**
     * @throws PropertyNeedsAClosingDate
     */
    public function closedOn(?DateTimeImmutable $day, string $note = ''): self
    {
        return new self(UnitStatus::Ended, Closure::on($day, $note));
    }

    /** Zurueckholen: die Einheit ist wieder in Verwaltung. */
    public function reopened(): self
    {
        return self::active();
    }

    /**
     * Ein frueherer Stichtag bleibt stehen.
     *
     * Wird ein Objekt abgewickelt, endet alles daran zum selben Tag — nur
     * nicht das, was schon vorher endete. Der Stichtag setzt ein Ende, wo
     * keines steht; er verschiebt keines.
     */
    public function closedNoLaterThan(DateTimeImmutable $day, string $note = ''): self
    {
        return $this->status->isPast() ? $this : $this->closedOn($day, $note);
    }
}
