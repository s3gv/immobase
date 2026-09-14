<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Party\Domain;

use App\Shared\Text\Trimmed;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Steuernummer oder Umsatzsteuer-Identifikationsnummer.
 *
 * **Ein Feld fuer beides**, weil § 14 Abs. 4 Nr. 2 UStG beides zulaesst und
 * niemand zweimal gefragt werden soll, was er ohnehin nur einmal hat.
 *
 * Gebraucht wird sie beim **Eigentuemer**: eine Dauermietrechnung nennt die
 * Nummer des leistenden Unternehmers, und das ist der Vermieter — nicht die
 * Verwaltung, die das Schreiben aufsetzt. Bei allen anderen Rollen bleibt sie
 * leer, und leer ist hier kein Mangel.
 *
 * Keine Pruefung auf Bauart: Steuernummern sehen je Bundesland anders aus,
 * USt-IdNr. je Land, und eine Pruefung, die zwei Drittel der Faelle kennt,
 * weist die uebrigen zurueck.
 */
#[ORM\Embeddable]
final class TaxId
{
    #[ORM\Column(name: 'tax_number', type: Types::STRING, length: 40)]
    private string $number;

    private function __construct(string $number)
    {
        $this->number = $number;
    }

    public static function none(): self
    {
        return new self('');
    }

    public static function of(string $number): self
    {
        return new self(Trimmed::orNull($number) ?? '');
    }

    public function toString(): string
    {
        return $this->number;
    }

    public function isKnown(): bool
    {
        return '' !== $this->number;
    }
}
