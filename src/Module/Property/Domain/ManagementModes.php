<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Property\Domain;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use InvalidArgumentException;

/**
 * Die Verwaltungsarten eines Objekts — mindestens eine, gern mehrere.
 *
 * Gespeichert als abgegrenzte Zeichenkette, etwa "|weg|sev|", und nicht als
 * JSON-Liste: die Uebersicht filtert danach, und DQL kennt kein CAST, mit dem
 * sich eine JSON-Spalte durchsuchen liesse. Dasselbe Muster wie bei den Rollen
 * eines Stammdatensatzes.
 */
#[ORM\Embeddable]
final class ManagementModes
{
    private const string SEPARATOR = '|';

    /**
     * Heisst absichtlich nicht "modes": als eingebettetes Objekt lautet der
     * Pfad in einer Abfrage sonst "p.modes.modes".
     */
    #[ORM\Column(name: 'modes', type: Types::STRING, length: 32)]
    private string $value = '';

    private function __construct()
    {
    }

    /**
     * @param list<ManagementMode> $modes mindestens eine
     */
    public static function of(array $modes): self
    {
        $values = array_values(array_unique(array_map(
            static fn (ManagementMode $mode): string => $mode->value,
            $modes,
        )));

        if ([] === $values) {
            throw new InvalidArgumentException('Mindestens eine Verwaltungsart ist nötig.');
        }

        $set = new self();
        $set->value = self::marked(implode(self::SEPARATOR, $values));

        return $set;
    }

    /**
     * Eine Verwaltungsart so, wie sie in der Spalte steht — mit Trennzeichen
     * ringsum. Dieselbe Form braucht die Abfrage in der Infrastruktur.
     */
    public static function marked(string $mode): string
    {
        return self::SEPARATOR.$mode.self::SEPARATOR;
    }

    /**
     * @return list<ManagementMode>
     */
    public function all(): array
    {
        $names = array_filter(
            explode(self::SEPARATOR, $this->value),
            static fn (string $name): bool => '' !== $name,
        );

        return array_values(array_map(ManagementMode::from(...), $names));
    }

    public function has(ManagementMode $mode): bool
    {
        return str_contains($this->value, self::marked($mode->value));
    }

    /**
     * Fuehrt dieses Objekt eine Erhaltungsruecklage?
     *
     * Nur bei WEG-Verwaltung: die Ruecklage gehoert der Gemeinschaft und
     * liegt auf deren Konto (§ 19 Abs. 2 Nr. 4 WEG). Bei reiner
     * Mietverwaltung gibt es keine Gemeinschaft, und bei
     * Sondereigentumsverwaltung verwaltet man das Sondereigentum eines
     * Eigentuemers — der zahlt Hausgeld ein, das jemand anderes fuehrt.
     *
     * Enger als needMea(): dort zaehlt auch SEV mit, weil die Anteile am
     * Sondereigentum haengen. Die Ruecklage tut das nicht.
     */
    public function keepAReserve(): bool
    {
        return \in_array(ManagementMode::Weg, $this->all(), true);
    }

    /**
     * Braucht dieses Objekt Miteigentumsanteile?
     *
     * Reine Mietverwaltung kennt keine. Dann entfaellt der Schritt beim
     * Anlegen und der Abschnitt auf der Objektseite — ein Feld, das nichts
     * bedeutet, ist schlimmer als keins.
     */
    public function needMea(): bool
    {
        foreach ($this->all() as $mode) {
            if ($mode->needsMea()) {
                return true;
            }
        }

        return false;
    }
}
