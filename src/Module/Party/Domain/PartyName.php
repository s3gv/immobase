<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Party\Domain;

use App\Shared\Text\Trimmed;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Wie eine Partei heisst — und wonach sie sortiert wird.
 *
 * Drei Felder, die zusammengehoeren und darum zusammen stehen: das zweite
 * heisst bei einer Person „Vorname" und bei einer Firma „Ansprechpartner",
 * und das dritte wird aus beiden **abgeleitet**. Eine Suche, die nur den
 * Nachnamen findet, waere in einer Liste von Menschen wenig hilfreich.
 *
 * Die Sortierform wird gespeichert und nicht bei jeder Abfrage gerechnet:
 * darauf liegt ein Index, und ein Index auf einen Ausdruck ist einer, den
 * niemand mehr versteht.
 *
 * Ob der Vorname vor den Nachnamen gehoert, weiss dieser Wert nicht: bei
 * einer Firma waere „Max Mustermann GmbH" aus Firmenname und Ansprechpartner
 * zusammengesetzter Unsinn. Die Art kommt darum von aussen herein.
 */
#[ORM\Embeddable]
final class PartyName
{
    /** Nachname oder Firmenname. */
    #[ORM\Column(type: Types::STRING, length: 200)]
    private string $name;

    /** Vorname bei Personen, Ansprechpartner bei Firmen. */
    #[ORM\Column(name: 'given_name', type: Types::STRING, length: 200, nullable: true)]
    private ?string $givenName;

    #[ORM\Column(name: 'sort_name', type: Types::STRING, length: 401)]
    private string $sortName;

    private function __construct(string $name, ?string $givenName)
    {
        $this->name = Trimmed::required($name, 'Name');
        $this->givenName = Trimmed::orNull($givenName);
        $this->sortName = mb_strtolower(trim($this->name.' '.($this->givenName ?? '')));
    }

    public static function of(string $name, ?string $givenName): self
    {
        return new self($name, $givenName);
    }

    public function name(): string
    {
        return $this->name;
    }

    public function givenName(): ?string
    {
        return $this->givenName;
    }

    public function sortName(): string
    {
        return $this->sortName;
    }

    /** Der vollstaendige Name, wie er in Listen und Anschreiben steht. */
    public function displayFor(PartyKind $kind): string
    {
        if (PartyKind::Company === $kind || null === $this->givenName) {
            return $this->name;
        }

        return $this->givenName.' '.$this->name;
    }
}
