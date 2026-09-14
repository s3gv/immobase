<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Party\Application;

use App\Module\Party\Domain\Addresses;
use App\Module\Party\Domain\AddressKind;
use App\Module\Party\Domain\ContactDetails;
use App\Module\Party\Domain\Party;
use App\Module\Party\Domain\PartyKind;
use App\Module\Party\Domain\PartyRole;
use App\Module\Party\Domain\PartyRoles;
use App\Module\Party\Domain\PostalAddress;
use App\Shared\Contact\Email;
use App\Shared\Text\Trimmed;

/**
 * Der Zwischenstand eines Erfassungsablaufs, auf dem Weg zum Stammdatensatz.
 *
 * Liest die Angaben aus dem Ablauf und macht daraus Wertobjekte. Sie liegen
 * dort als einfache Zeichenketten und Listen, weil der Ablauf sie zwischen
 * zwei Aufrufen in der Sitzung haelt.
 *
 * Bei Anschriften, E-Mail-Adressen und Telefonnummern gilt durchgaengig: die
 * erste ist die wichtigste. Die Reihenfolge traegt die Bedeutung, es gibt kein
 * zusaetzliches Kennzeichen.
 */
final readonly class PartyDraft
{
    private readonly DraftValues $values;

    /**
     * @param array<string, array<string, mixed>> $values
     */
    public function __construct(array $values)
    {
        $this->values = new DraftValues($values);
    }

    public function kind(): PartyKind
    {
        return PartyKind::tryFrom($this->values->text('kind', 'kind')) ?? PartyKind::Person;
    }

    /**
     * @return list<PartyRole>
     */
    public function roles(): array
    {
        $roles = array_map(
            static fn (string $role): ?PartyRole => PartyRole::tryFrom($role),
            $this->values->texts('kind', 'roles'),
        );

        return array_values(array_filter($roles, static fn (?PartyRole $role): bool => null !== $role));
    }

    public function name(): string
    {
        return $this->values->text('name', 'name');
    }

    public function givenName(): ?string
    {
        return Trimmed::orNull($this->values->text('name', 'givenName'));
    }

    /**
     * Die erfassten Anschriften, die erste ist die Hauptanschrift.
     *
     * @return list<array{kind: string, addition: string, line: string, postalCode: string, city: string}>
     */
    public function addressRows(): array
    {
        $rows = [];

        foreach ($this->values->rows('address', 'entries') as $entry) {
            $rows[] = [
                'kind' => DraftValues::field($entry, 'kind'),
                'addition' => DraftValues::field($entry, 'addition'),
                'line' => DraftValues::field($entry, 'line'),
                'postalCode' => DraftValues::field($entry, 'postalCode'),
                'city' => DraftValues::field($entry, 'city'),
            ];
        }

        return $rows;
    }

    /**
     * Die Adressen ohne leere Zeilen — was daraus ein Wertobjekt wird.
     *
     * @return list<string>
     */
    public function emails(): array
    {
        return DraftValues::withoutBlanks($this->emailInputs());
    }

    /**
     * Die Adressen so, wie sie im Formular stehen — samt leerer Zeilen.
     *
     * Das Formular braucht sie ungefiltert: eine frisch hinzugefuegte Zeile
     * ist zwangslaeufig leer, und wuerde sie hier verschwinden, waere der
     * Knopf "hinzufuegen" wirkungslos.
     *
     * @return list<string>
     */
    public function emailInputs(): array
    {
        return $this->values->texts('contact', 'emails');
    }

    /**
     * @return list<string>
     */
    public function phones(): array
    {
        return DraftValues::withoutBlanks($this->phoneInputs());
    }

    /**
     * @return list<string>
     */
    public function phoneInputs(): array
    {
        return $this->values->texts('contact', 'phones');
    }

    public function note(): string
    {
        return $this->values->text('note', 'note');
    }

    /**
     * Steuernummer oder USt-IdNr. — nur beim Eigentuemer gefragt.
     *
     * Gelesen wird sie trotzdem immer: wer die Rolle wieder abwaehlt, soll
     * die Nummer nicht dadurch verlieren, dass das Feld beim naechsten
     * Aufruf nicht mehr dasteht.
     */
    public function taxNumber(): string
    {
        return $this->values->text('contact', 'taxNumber');
    }

    public function addresses(): Addresses
    {
        $addresses = array_map(
            static fn (array $row): PostalAddress => PostalAddress::of(
                AddressKind::tryFrom($row['kind']) ?? AddressKind::Street,
                $row['line'],
                $row['postalCode'],
                $row['city'],
                $row['addition'],
            ),
            $this->addressRows(),
        );

        return Addresses::of($addresses);
    }

    public function contact(): ContactDetails
    {
        return ContactDetails::of(array_map(Email::fromString(...), $this->emails()), $this->phones());
    }

    public function toParty(int $reference): Party
    {
        return new Party(
            $reference,
            $this->kind(),
            $this->name(),
            $this->givenName(),
            PartyRoles::of($this->roles()),
            $this->addresses(),
            $this->contact(),
            $this->note(),
        );
    }

    /** Traegt die Rolle Eigentuemer? Nur dann wird nach der Steuernummer gefragt. */
    public function letsProperty(): bool
    {
        return \in_array(PartyRole::Owner, $this->roles(), true);
    }
}
