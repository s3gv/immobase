<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Shared\Bank;

/**
 * Eine BIC als Wertobjekt.
 *
 * Acht oder elf Stellen: vier fuer die Bank, zwei fuer das Land, zwei fuer
 * den Ort und wahlweise drei fuer die Filiale. Mehr laesst sich ohne
 * Verzeichnis nicht pruefen — es gibt keine Pruefziffer.
 *
 * Bei SEPA im Inland ist sie entbehrlich; sie steht trotzdem hier, weil sie
 * auf mancher Rechnung erwartet wird und im Ausland weiter gebraucht wird.
 */
final readonly class Bic
{
    private const string SHAPE = '/^[A-Z]{4}[A-Z]{2}[A-Z0-9]{2}([A-Z0-9]{3})?$/D';

    private function __construct(private string $value)
    {
    }

    /**
     * @throws NotABic
     */
    public static function fromString(string $value): self
    {
        $normalised = strtoupper(str_replace(' ', '', trim($value)));

        if (1 !== preg_match(self::SHAPE, $normalised)) {
            throw new NotABic();
        }

        return new self($normalised);
    }

    /** Leere Eingabe heisst „nicht angegeben" und ist kein Fehler. */
    public static function orNull(string $value): ?self
    {
        return '' === trim($value) ? null : self::fromString($value);
    }

    public function toString(): string
    {
        return $this->value;
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }
}
