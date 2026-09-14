<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Billing\Domain;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Welche Arten ein Lauf erzeugen soll.
 *
 * Zwei Haekchen, beide vorbelegt: „beide" deckt jeden Fall ab und ist im
 * Zweifel die richtige Wahl. Wer nur eine Sorte will, nimmt eine heraus.
 *
 * Keines von beiden ist erlaubt und heisst schlicht: nichts zu tun. Es
 * abzufangen waere eine Regel gegen eine Eingabe, die sich von selbst
 * beantwortet — die Empfaengerliste bliebe leer, und das sieht man.
 */
#[ORM\Embeddable]
final class StatementKinds
{
    #[ORM\Column(name: 'for_owners', type: Types::BOOLEAN)]
    private bool $forOwners;

    #[ORM\Column(name: 'for_tenants', type: Types::BOOLEAN)]
    private bool $forTenants;

    private function __construct(bool $forOwners, bool $forTenants)
    {
        $this->forOwners = $forOwners;
        $this->forTenants = $forTenants;
    }

    public static function both(): self
    {
        return new self(true, true);
    }

    public static function of(bool $forOwners, bool $forTenants): self
    {
        return new self($forOwners, $forTenants);
    }

    public function has(StatementKind $kind): bool
    {
        return match ($kind) {
            StatementKind::HouseMoney => $this->forOwners,
            StatementKind::OperatingCosts => $this->forTenants,
        };
    }

    /** Fuer die Vorlage: sie soll kein Enum durchreichen muessen. */
    public function forOwners(): bool
    {
        return $this->forOwners;
    }

    public function forTenants(): bool
    {
        return $this->forTenants;
    }

    public function isEmpty(): bool
    {
        return !$this->forOwners && !$this->forTenants;
    }

    /** @return list<StatementKind> */
    public function all(): array
    {
        return array_values(array_filter(StatementKind::cases(), $this->has(...)));
    }
}
