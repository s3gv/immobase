<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Property\Domain;

use App\Shared\Text\Trimmed;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Wo das Objekt steht.
 *
 * Eine Anschrift, nicht mehrere: ein Gebaeude hat einen Standort. Die
 * Stammdaten kennen mehrere Anschriften je Datensatz und dazu eine Art
 * (Post, Rechnung) — beides gibt es hier nicht, und deshalb ist es auch nicht
 * dasselbe Wertobjekt.
 *
 * Eine Hausnummernspalte gibt es bewusst nicht: „Musterweg 1a-3" und
 * „Am Markt 7, Hinterhaus" sind gueltige Adressen, die sich nicht zerlegen
 * lassen, ohne dass etwas verloren geht.
 */
#[ORM\Embeddable]
final class Address
{
    #[ORM\Column(name: 'street', type: Types::STRING, length: 200)]
    private string $street = '';

    #[ORM\Column(name: 'postal_code', type: Types::STRING, length: 16)]
    private string $postalCode = '';

    #[ORM\Column(name: 'city', type: Types::STRING, length: 120)]
    private string $city = '';

    private function __construct()
    {
    }

    public static function unknown(): self
    {
        return new self();
    }

    public static function of(string $street, string $postalCode, string $city): self
    {
        $address = new self();
        $address->street = Trimmed::required($street, 'Die Straße');
        $address->postalCode = Trimmed::required($postalCode, 'Die Postleitzahl');
        $address->city = Trimmed::required($city, 'Der Ort');

        return $address;
    }

    public function street(): string
    {
        return $this->street;
    }

    public function postalCode(): string
    {
        return $this->postalCode;
    }

    public function city(): string
    {
        return $this->city;
    }

    public function isKnown(): bool
    {
        return '' !== $this->street;
    }

    public function oneLine(): string
    {
        return $this->isKnown()
            ? \sprintf('%s, %s %s', $this->street, $this->postalCode, $this->city)
            : '';
    }
}
