<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Property\Domain;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Die Heizung des Objekts — Energietraeger, Anlage, Warmwasser.
 *
 * Drei Angaben, die zusammen gelesen werden muessen: erst aus „zentral" und
 * „verbunden" folgt, dass eine Heizkostenabrechnung noetig ist und wie sie
 * aufzuteilen ist. Einzeln verteilt saehe man den Zusammenhang nicht.
 *
 * Alle drei duerfen fehlen: beim Anlegen weiss man sie oft noch nicht.
 */
#[ORM\Embeddable]
final class Heating
{
    #[ORM\Column(name: 'heating_type', type: Types::STRING, length: 16, nullable: true, enumType: HeatingType::class)]
    private ?HeatingType $type = null;

    #[ORM\Column(name: 'heating_system', type: Types::STRING, length: 16, nullable: true, enumType: HeatingSystem::class)]
    private ?HeatingSystem $system = null;

    #[ORM\Column(name: 'hot_water', type: Types::STRING, length: 16, nullable: true, enumType: HotWater::class)]
    private ?HotWater $hotWater = null;

    private function __construct()
    {
    }

    public static function unknown(): self
    {
        return new self();
    }

    public static function of(?HeatingType $type, ?HeatingSystem $system, ?HotWater $hotWater): self
    {
        $heating = new self();
        $heating->type = $type;
        $heating->system = $system;
        $heating->hotWater = $hotWater;

        return $heating;
    }

    public function type(): ?HeatingType
    {
        return $this->type;
    }

    public function system(): ?HeatingSystem
    {
        return $this->system;
    }

    public function hotWater(): ?HotWater
    {
        return $this->hotWater;
    }

    /**
     * Muss die Abrechnung Heizung und Warmwasser trennen?
     *
     * Nur bei einer Anlage fuers ganze Haus, die beides macht.
     */
    public function needsSplitBilling(): bool
    {
        return HeatingSystem::Central === $this->system && HotWater::Connected === $this->hotWater;
    }

    public function isKnown(): bool
    {
        return null !== $this->type || null !== $this->system || null !== $this->hotWater;
    }
}
