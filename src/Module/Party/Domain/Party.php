<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Party\Domain;

use App\Shared\Identity\Uuid;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Ein Stammdatensatz: Mieter, Eigentuemer oder sonstiger Kontakt.
 *
 * Wie die Benutzer-Entity weder readonly noch final — Doctrine braucht
 * veraenderbare Objekte und erzeugt Proxy-Klassen.
 *
 * Anschrift und Kontaktangaben liegen als eingebettete Wertobjekte daneben.
 * Sie haben eigene Regeln — Strasse oder Postfach, mindestens eine
 * E-Mail-Adresse —, und die gehoeren dorthin, wo die Daten liegen.
 */
#[ORM\Entity]
#[ORM\Table(name: 'party')]
#[ORM\UniqueConstraint(name: 'party_reference', columns: ['reference'])]
#[ORM\Index(name: 'party_sort_name', columns: ['sort_name'])]
class Party
{
    #[ORM\Id]
    #[ORM\Column(type: Types::GUID)]
    private string $id;

    /**
     * Die sichtbare Nummer, an der sich Vorgaenge zuordnen lassen.
     *
     * Durchlaufend fuer alle Rollen und unveraenderlich: wer spaeter
     * zusaetzlich Eigentuemer wird, behaelt seine Nummer. Eine Nummer, die
     * sich aendert, taugt nicht zum Zuordnen.
     */
    #[ORM\Column(type: Types::INTEGER)]
    private int $reference;

    #[ORM\Column(type: Types::STRING, length: 16, enumType: PartyKind::class)]
    private PartyKind $kind;

    #[ORM\Embedded(class: PartyName::class, columnPrefix: false)]
    private PartyName $partyName;

    #[ORM\Embedded(class: PartyRoles::class, columnPrefix: false)]
    private PartyRoles $roles;

    #[ORM\Embedded(class: Addresses::class, columnPrefix: false)]
    private Addresses $addresses;

    #[ORM\Embedded(class: ContactDetails::class, columnPrefix: 'contact_')]
    private ContactDetails $contact;

    #[ORM\Embedded(class: TaxId::class, columnPrefix: false)]
    private TaxId $taxId;

    #[ORM\Column(type: Types::TEXT)]
    private string $note = '';

    #[ORM\Column(type: Types::STRING, length: 16, enumType: PartyStatus::class)]
    private PartyStatus $status = PartyStatus::Active;

    public function __construct(
        int $reference,
        PartyKind $kind,
        string $name,
        ?string $givenName,
        PartyRoles $roles,
        Addresses $addresses,
        ContactDetails $contact,
        string $note = '',
    ) {
        $this->id = Uuid::v4();
        $this->reference = $reference;
        $this->kind = $kind;
        $this->rename($name, $givenName);
        $this->roles = $roles;
        $this->addresses = $addresses;
        $this->contact = $contact;
        $this->note = $note;
        $this->taxId = TaxId::none();
    }

    public function id(): string
    {
        return $this->id;
    }

    public function reference(): int
    {
        return $this->reference;
    }

    public function kind(): PartyKind
    {
        return $this->kind;
    }

    public function name(): string
    {
        return $this->partyName->name();
    }

    public function givenName(): ?string
    {
        return $this->partyName->givenName();
    }

    public function displayName(): string
    {
        return $this->partyName->displayFor($this->kind);
    }

    public function sortName(): string
    {
        return $this->partyName->sortName();
    }

    public function roles(): PartyRoles
    {
        return $this->roles;
    }

    public function addresses(): Addresses
    {
        return $this->addresses;
    }

    public function status(): PartyStatus
    {
        return $this->status;
    }

    public function isArchived(): bool
    {
        return PartyStatus::Archived === $this->status;
    }

    public function contact(): ContactDetails
    {
        return $this->contact;
    }

    public function taxId(): TaxId
    {
        return $this->taxId;
    }

    public function taxedAs(TaxId $taxId): void
    {
        $this->taxId = $taxId;
    }

    public function note(): string
    {
        return $this->note;
    }

    public function rename(string $name, ?string $givenName): void
    {
        $this->partyName = PartyName::of($name, $givenName);
    }

    /**
     * Person oder Firma — nachtraeglich korrigierbar.
     *
     * Wer versehentlich als Person erfasst wurde, obwohl er eine Firma ist,
     * soll nicht neu angelegt werden muessen: die Referenznummer haengt an
     * Vorgaengen und ein zweiter Datensatz zerrisse sie.
     */
    public function classifyAs(PartyKind $kind): void
    {
        $this->kind = $kind;
    }

    public function assignRoles(PartyRoles $roles): void
    {
        $this->roles = $roles;
    }

    public function moveTo(Addresses $addresses): void
    {
        $this->addresses = $addresses;
    }

    public function archive(): void
    {
        $this->status = PartyStatus::Archived;
    }

    public function reactivate(): void
    {
        $this->status = PartyStatus::Active;
    }

    public function reachAt(ContactDetails $contact): void
    {
        $this->contact = $contact;
    }

    public function noteThat(string $note): void
    {
        $this->note = $note;
    }
}
