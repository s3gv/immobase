<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Party\Domain;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use InvalidArgumentException;

/**
 * Wozu ein Stammdatensatz gehoert — mindestens eine Rolle, gern mehrere.
 *
 * Gespeichert als abgegrenzte Zeichenkette, etwa "|tenant|owner|", und nicht
 * als JSON-Liste: die Uebersicht filtert nach Rolle, und DQL kennt kein CAST,
 * mit dem sich eine JSON-Spalte durchsuchen liesse. Die Trennzeichen aussen
 * sorgen dafuer, dass eine Rolle nicht in einer laengeren steckenbleibt.
 */
#[ORM\Embeddable]
final class PartyRoles
{
    private const string SEPARATOR = '|';

    /**
     * Heisst absichtlich nicht "roles": als eingebettetes Objekt lautet der
     * Pfad in einer Abfrage sonst "p.roles.roles".
     */
    #[ORM\Column(name: 'roles', type: Types::STRING, length: 64)]
    private string $value = '';

    private function __construct()
    {
    }

    /**
     * @param list<PartyRole> $roles mindestens eine
     */
    public static function of(array $roles): self
    {
        $values = array_values(array_unique(array_map(
            static fn (PartyRole $role): string => $role->value,
            $roles,
        )));

        if ([] === $values) {
            throw new InvalidArgumentException('Mindestens eine Rolle ist nötig.');
        }

        $set = new self();
        $set->value = self::marked(implode(self::SEPARATOR, $values));

        return $set;
    }

    /**
     * Eine Rolle so, wie sie in der Spalte steht — mit Trennzeichen ringsum.
     *
     * Dieselbe Form braucht die Abfrage in der Infrastruktur. Sie steht hier,
     * damit beide Seiten sie aus einer Quelle nehmen und nicht auseinander
     * laufen.
     */
    public static function marked(string $role): string
    {
        return self::SEPARATOR.$role.self::SEPARATOR;
    }

    /**
     * @return list<PartyRole>
     */
    public function all(): array
    {
        // Die Trennzeichen aussen erzeugen beim Zerlegen je einen leeren Teil.
        $names = array_filter(
            explode(self::SEPARATOR, $this->value),
            static fn (string $name): bool => '' !== $name,
        );

        return array_values(array_map(PartyRole::from(...), $names));
    }

    /**
     * Vermietet diese Partei?
     *
     * Eigens benannt, weil die Vorlage es fragt und dort keine Aufzaehlung
     * zur Hand hat. An der Rolle haengt, ob nach der Steuernummer gefragt
     * wird — sie steht auf jeder Dauermietrechnung des Eigentuemers.
     */
    public function includesOwner(): bool
    {
        return $this->has(PartyRole::Owner);
    }

    public function has(PartyRole $role): bool
    {
        return str_contains($this->value, self::marked($role->value));
    }
}
