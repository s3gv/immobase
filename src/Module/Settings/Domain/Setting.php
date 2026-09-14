<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Settings\Domain;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Eine gespeicherte Einstellung.
 *
 * Schluessel und Wert als Text: die Tabelle nimmt jede kuenftige Option auf,
 * ohne dass eine Migration noetig wird. Was ein Wert bedeutet, weiss die
 * Stelle, die ihn liest — hier steht nur, was jemand eingestellt hat.
 */
#[ORM\Entity]
#[ORM\Table(name: 'settings')]
class Setting
{
    /** So breit wie die Spalte — eine Einstellung ist eine Angabe, kein Text. */
    public const int MOST_CHARACTERS = 500;

    #[ORM\Id]
    #[ORM\Column(type: Types::STRING, length: 100)]
    private string $name;

    #[ORM\Column(type: Types::STRING, length: self::MOST_CHARACTERS)]
    private string $value;

    /**
     * @throws SettingIsTooLong
     */
    public function __construct(string $name, string $value)
    {
        $this->name = $name;
        $this->changeTo($value);
    }

    public function name(): string
    {
        return $this->name;
    }

    public function value(): string
    {
        return $this->value;
    }

    /**
     * Die Laenge wird hier geprueft und nicht erst von der Spalte.
     *
     * Ein zu langer Wert waere sonst ein Datenbankfehler mitten im
     * Speichern — eine Absage, die niemand liest, an einer Stelle, an der
     * schon die Haelfte geschrieben ist.
     *
     * @throws SettingIsTooLong
     */
    public function changeTo(string $value): void
    {
        if (mb_strlen($value) > self::MOST_CHARACTERS) {
            throw SettingIsTooLong::at($this->name);
        }

        $this->value = $value;
    }
}
