<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Property\Domain;

use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Wo dieses Objekt in der Verwaltung steht.
 *
 * Der Zustand und der Stichtag gehoeren zusammen und werden nur zusammen
 * geaendert: „beendet" ohne Tag waere fuer jede spaetere Abrechnung wertlos,
 * ein Tag ohne „beendet" eine Angabe ohne Folge. Deshalb ein Wertobjekt und
 * kein Paar loser Felder — wie bei der Anschrift und dem Gebaeude auch.
 *
 * Die Uebergaenge sind Fragen mit einer Antwort: aus dem Entwurf wird ein
 * Objekt, aus dem Objekt ein beendetes, und ein versehentlich beendetes laesst
 * sich zurueckholen.
 */
#[ORM\Embeddable]
final class Management
{
    #[ORM\Column(name: 'status', type: Types::STRING, length: 16, enumType: PropertyStatus::class)]
    private PropertyStatus $status;

    #[ORM\Embedded(class: Closure::class, columnPrefix: false)]
    private Closure $closure;

    private function __construct(PropertyStatus $status, Closure $closure)
    {
        $this->status = $status;
        $this->closure = $closure;
    }

    /** Angelegt heisst Entwurf: der letzte Schritt macht daraus ein Objekt. */
    public static function draft(): self
    {
        return new self(PropertyStatus::Draft, Closure::none());
    }

    public function status(): PropertyStatus
    {
        return $this->status;
    }

    public function closure(): Closure
    {
        return $this->closure;
    }

    public function activated(): self
    {
        return new self(PropertyStatus::Active, Closure::none());
    }

    /**
     * @throws PropertyNeedsAClosingDate
     */
    public function closedOn(?DateTimeImmutable $day, string $note = ''): self
    {
        return new self(PropertyStatus::Ended, Closure::on($day, $note));
    }

    /** Zurueckholen — die Einheiten kommen dabei nicht mit; siehe Closure. */
    public function reopened(): self
    {
        return $this->activated();
    }
}
