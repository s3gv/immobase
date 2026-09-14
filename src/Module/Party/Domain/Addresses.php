<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Party\Domain;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use InvalidArgumentException;
use LogicException;

/**
 * Alle Anschriften eines Stammdatensatzes, die erste ist die Hauptanschrift.
 *
 * Die Reihenfolge traegt die Bedeutung; es gibt kein zusaetzliches Kennzeichen
 * "ist Hauptanschrift". Zwei Angaben fuer dieselbe Sache koennen einander
 * widersprechen — eine Reihenfolge kann das nicht.
 */
#[ORM\Embeddable]
final class Addresses
{
    /** @var list<array<string, string>> */
    #[ORM\Column(name: 'addresses', type: Types::JSON)]
    private array $addresses = [];

    private function __construct()
    {
    }

    /**
     * @param list<PostalAddress> $addresses mindestens eine
     */
    public static function of(array $addresses): self
    {
        if ([] === $addresses) {
            throw new InvalidArgumentException('Mindestens eine Anschrift ist nötig.');
        }

        $set = new self();
        $set->addresses = array_values(array_map(
            static fn (PostalAddress $address): array => $address->toArray(),
            $addresses,
        ));

        return $set;
    }

    /**
     * @return list<PostalAddress>
     */
    public function all(): array
    {
        return array_values(array_map(PostalAddress::fromArray(...), $this->addresses));
    }

    /**
     * Die Anschrift, an die geschrieben wird, wenn nur eine gemeint sein kann.
     */
    public function primary(): PostalAddress
    {
        return $this->all()[0] ?? throw new LogicException('Stammdatensatz ohne Anschrift.');
    }

    public function count(): int
    {
        return \count($this->addresses);
    }
}
