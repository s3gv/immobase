<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Property\Domain;

use App\Shared\Number\Quantity;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use InvalidArgumentException;

/**
 * Was sich an einer Einheit messen laesst: Flaeche, Zimmer, Stellplaetze.
 *
 * Drei Angaben, die zusammen erfasst werden und zusammen den Schritt
 * „Details" ausmachen — wie die Anschrift und das Gebaeude beim Objekt. Leer
 * ist erlaubt: was man nicht weiss, traegt man nicht ein.
 */
#[ORM\Embeddable]
final class Measures
{
    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 2, nullable: true)]
    private ?string $area = null;

    /**
     * Die Zimmerzahl ist eine Angabe und keine Rechengroesse: was im
     * Mietvertrag oder in der Teilungserklaerung steht, steht auch hier —
     * „2,5" ebenso wie „3,25". Zwei Nachkommastellen, wie bei den Flaechen;
     * eine haette stillschweigend gerundet, und eine stille Rundung ist
     * schlimmer als eine Fehlermeldung.
     */
    #[ORM\Column(type: Types::DECIMAL, precision: 5, scale: 2, nullable: true)]
    private ?string $rooms = null;

    #[ORM\Column(name: 'parking_spaces', type: Types::SMALLINT, nullable: true)]
    private ?int $parkingSpaces = null;

    private function __construct()
    {
    }

    public static function unknown(): self
    {
        return new self();
    }

    /**
     * @throws InvalidArgumentException
     */
    public static function of(?string $area, ?string $rooms, ?int $parkingSpaces): self
    {
        $measures = new self();
        $measures->area = Area::orNull($area);
        $measures->rooms = Area::orNull($rooms);
        $measures->parkingSpaces = Quantity::orNull($parkingSpaces, 'Die Zahl der Stellplätze');

        return $measures;
    }

    public function area(): ?string
    {
        return $this->area;
    }

    public function rooms(): ?string
    {
        return $this->rooms;
    }

    public function parkingSpaces(): ?int
    {
        return $this->parkingSpaces;
    }
}
