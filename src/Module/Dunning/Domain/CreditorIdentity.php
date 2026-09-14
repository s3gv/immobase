<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Dunning\Domain;

/**
 * Wer fordert — vollstaendig, nicht nur der Rolle nach.
 *
 * „Die Gemeinschaft" und „der Eigentuemer" sind Rollen und keine Personen.
 * Gemeint ist die Gemeinschaft **dieses** Objekts, und beim Vermieter der
 * Eigentuemer **dieser** Einheit zum Tag der Faelligkeit. Erst beides zusammen
 * sagt, in wessen Namen geschrieben wird.
 *
 * Daran haengt mehr als eine Anschrift: die Buendelung („ein Schreiben, ein
 * Glaeubiger"), die Reihenfolge der Mahnstufen und die Zusammenfassung fuers
 * Amtsgericht. Wer zwei Wohnungen im selben Haus von zwei verschiedenen
 * Eigentuemern mietet, schuldet zwei Leuten — ein gemeinsames Schreiben traege
 * die Anschrift des einen und forderte das Geld des anderen mit ein.
 *
 * **Bei der Gemeinschaft ist die Parteienliste leer.** Eine eigene Partei fuer
 * sie gibt es nicht; sie heisst nach ihrem Objekt, und das Objekt steht
 * daneben.
 */
final readonly class CreditorIdentity
{
    /**
     * @param string $partyIds die Eigentuemer, aufsteigend sortiert und mit Komma
     *                         verbunden — leer bei der Gemeinschaft
     */
    private function __construct(
        public Creditor $role,
        public string $propertyId,
        public string $partyIds,
    ) {
    }

    /** Die Gemeinschaft eines Objekts. */
    public static function theCommunityOf(string $propertyId): self
    {
        return new self(Creditor::Community, $propertyId, '');
    }

    /**
     * Der oder die Eigentuemer einer Einheit.
     *
     * Sortiert, damit dieselben Leute in anderer Reihenfolge derselbe
     * Glaeubiger sind und nicht ein zweiter.
     *
     * @param list<string> $partyIds
     */
    public static function theOwners(string $propertyId, array $partyIds): self
    {
        sort($partyIds);

        return new self(Creditor::Owner, $propertyId, implode(',', $partyIds));
    }

    /** Wie sie gespeichert dasteht — aus den Spalten zurueckgelesen. */
    public static function stored(Creditor $role, string $propertyId, string $partyIds): self
    {
        return new self($role, $propertyId, $partyIds);
    }

    /** @return list<string> */
    public function parties(): array
    {
        return '' === $this->partyIds ? [] : explode(',', $this->partyIds);
    }

    public function equals(self $other): bool
    {
        return $this->role === $other->role
            && $this->propertyId === $other->propertyId
            && $this->partyIds === $other->partyIds;
    }

    /** Ein Schluessel zum Zaehlen — ein Schreiben je verschiedenem Wert. */
    public function key(): string
    {
        return $this->role->value.'|'.$this->propertyId.'|'.$this->partyIds;
    }
}
