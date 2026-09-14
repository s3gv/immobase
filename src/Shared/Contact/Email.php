<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Shared\Contact;

use InvalidArgumentException;

/**
 * E-Mail-Adresse als Wertobjekt.
 *
 * Normalisiert auf Kleinschreibung und ohne umgebende Leerzeichen, damit sich
 * derselbe Mensch nicht zweimal anlegen kann.
 *
 * Liegt in Shared und nicht im Auth-Modul: eine Adresse ist kein Begriff der
 * Anmeldung. Auth benutzt sie als Anmeldenamen, die Stammdaten als
 * Kontaktangabe — dieselbe Pruefung, an einer Stelle.
 */
final readonly class Email
{
    /**
     * @param non-empty-string $value
     */
    private function __construct(private string $value)
    {
    }

    public static function fromString(string $value): self
    {
        $normalised = mb_strtolower(trim($value));

        // Die Leerprüfung steht getrennt, obwohl filter_var eine leere
        // Zeichenkette ohnehin ablehnt: nur als eigene Bedingung ist die
        // Nichtleere für die statische Analyse belegt, und Symfony verlangt
        // für den Benutzerbezeichner non-empty-string.
        if ('' === $normalised) {
            throw new InvalidArgumentException('Die E-Mail-Adresse darf nicht leer sein.');
        }

        if (false === filter_var($normalised, \FILTER_VALIDATE_EMAIL)) {
            throw new InvalidArgumentException(\sprintf('Keine gültige E-Mail-Adresse: "%s".', $value));
        }

        return new self($normalised);
    }

    /**
     * @return non-empty-string
     */
    public function toString(): string
    {
        return $this->value;
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }
}
