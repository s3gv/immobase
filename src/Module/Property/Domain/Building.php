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
 * Was das Gebaeude ausmacht.
 *
 * Flaechen als Dezimalzahl in der Datenbank und als Zeichenkette in PHP:
 * Fliesskomma sammelt beim Verteilen von Kosten nach Flaeche dieselben
 * Rundungsfehler ein wie beim Geld.
 *
 * Wohn- und Gewerbeflaeche stehen getrennt, weil bei Gewerbe Umsatzsteuer
 * ausgewiesen wird und bei Wohnraum nicht. Zusammengezaehlt liesse sich das
 * spaeter nicht mehr trennen.
 *
 * Alles darf fehlen. Beim Anlegen ist oft nur die Adresse bekannt.
 */
#[ORM\Embeddable]
final class Building
{
    private const int OLDEST_YEAR = 1200;

    #[ORM\Column(name: 'year_built', type: Types::INTEGER, nullable: true)]
    private ?int $yearBuilt = null;

    #[ORM\Column(name: 'living_area', type: Types::DECIMAL, precision: 10, scale: 2, nullable: true)]
    private ?string $livingArea = null;

    #[ORM\Column(name: 'commercial_area', type: Types::DECIMAL, precision: 10, scale: 2, nullable: true)]
    private ?string $commercialArea = null;

    #[ORM\Column(name: 'plot_area', type: Types::DECIMAL, precision: 10, scale: 2, nullable: true)]
    private ?string $plotArea = null;

    #[ORM\Column(name: 'floors', type: Types::SMALLINT, nullable: true)]
    private ?int $floors = null;

    /** Null heisst „nicht erfasst" und nicht „kein Aufzug". */
    #[ORM\Column(name: 'has_lift', type: Types::BOOLEAN, nullable: true)]
    private ?bool $hasLift = null;

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
     * @param array{yearBuilt?: ?int, livingArea?: ?string, commercialArea?: ?string,
     *              plotArea?: ?string, floors?: ?int, hasLift?: ?bool, parkingSpaces?: ?int} $values
     */
    public static function of(array $values): self
    {
        $building = new self();
        $building->yearBuilt = self::checkedYear($values['yearBuilt'] ?? null);
        $building->livingArea = Area::orNull($values['livingArea'] ?? null);
        $building->commercialArea = Area::orNull($values['commercialArea'] ?? null);
        $building->plotArea = Area::orNull($values['plotArea'] ?? null);
        $building->floors = Quantity::orNull($values['floors'] ?? null, 'Die Zahl der Geschosse');
        $building->hasLift = $values['hasLift'] ?? null;
        $building->parkingSpaces = Quantity::orNull($values['parkingSpaces'] ?? null, 'Die Zahl der Stellplätze');

        return $building;
    }

    public function yearBuilt(): ?int
    {
        return $this->yearBuilt;
    }

    public function livingArea(): ?string
    {
        return $this->livingArea;
    }

    public function commercialArea(): ?string
    {
        return $this->commercialArea;
    }

    public function plotArea(): ?string
    {
        return $this->plotArea;
    }

    public function floors(): ?int
    {
        return $this->floors;
    }

    public function hasLift(): ?bool
    {
        return $this->hasLift;
    }

    public function parkingSpaces(): ?int
    {
        return $this->parkingSpaces;
    }

    private static function checkedYear(?int $year): ?int
    {
        if (null === $year) {
            return null;
        }

        if ($year < self::OLDEST_YEAR || $year > (int) date('Y') + 5) {
            throw new InvalidArgumentException('Das Baujahr liegt außerhalb des Möglichen.');
        }

        return $year;
    }
}
