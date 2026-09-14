<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Auth\Domain\Rbac;

use App\Shared\Text\Trimmed;
use InvalidArgumentException;

/**
 * Der Name einer Rolle.
 *
 * Er ist das Einzige, was jemand an einer Rolle eintippt, und er steht danach
 * in jeder Auswahlliste. Ein Name aus Leerzeichen oder ein Absatz Text waere
 * dort nicht mehr zu gebrauchen.
 */
final readonly class RoleName
{
    public const int MAX_LENGTH = 64;

    private function __construct(public string $value)
    {
    }

    /**
     * @throws InvalidArgumentException wenn leer oder zu lang
     */
    public static function fromString(string $input): self
    {
        $trimmed = Trimmed::orNull($input);

        if (null === $trimmed) {
            throw new InvalidArgumentException('Ein Rollenname darf nicht leer sein.');
        }

        if (mb_strlen($trimmed) > self::MAX_LENGTH) {
            throw new InvalidArgumentException('Ein Rollenname ist auf '.self::MAX_LENGTH.' Zeichen begrenzt.');
        }

        return new self($trimmed);
    }

    /**
     * Vergleich ohne Ruecksicht auf Gross- und Kleinschreibung.
     *
     * „Buchhaltung" und „buchhaltung" zweimal nebeneinander waeren fuer die
     * Datenbank zwei Rollen und fuer jeden Menschen davor eine.
     */
    public function equals(self $other): bool
    {
        return mb_strtolower($this->value) === mb_strtolower($other->value);
    }

    public function toString(): string
    {
        return $this->value;
    }
}
