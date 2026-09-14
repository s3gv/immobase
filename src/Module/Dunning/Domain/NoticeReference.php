<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Dunning\Domain;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * An wen das Schreiben geht und das wievielte es ist.
 *
 * `MA-98002-3` — die Nummer der **Partei**, nicht der Einheit, weil das
 * Schreiben an einen Menschen geht und nicht an eine Wohnung. Gezaehlt wird
 * je Schuldner: wer zwei Objekte in derselben Verwaltung hat, bekommt keine
 * zwei Zaehlreihen.
 *
 * Der Glaeubiger steht mit dabei, weil er die Buendelung bestimmt: ein
 * Schreiben, ein Glaeubiger. Und zwar vollstaendig ({@see CreditorIdentity})
 * — „die Gemeinschaft" ist keine Antwort, sondern „die Gemeinschaft Rosenweg
 * 12", und beim Vermieter der Eigentuemer dieser Einheit. Ein gemeinsames
 * Schreiben an zwei Glaeubiger traege die Anschrift und das Konto des einen
 * und forderte das Geld des anderen mit ein. Aus demselben Grund zaehlen die
 * Stufen je Glaeubiger: eine Erinnerung fuer den einen macht aus dem
 * naechsten Schreiben fuer den anderen keine erste Mahnung.
 */
#[ORM\Embeddable]
final class NoticeReference
{
    #[ORM\Column(name: 'debtor_party_id', type: Types::GUID)]
    private string $partyId;

    #[ORM\Column(name: 'debtor_number', type: Types::INTEGER)]
    private int $partyNumber;

    #[ORM\Column(type: Types::STRING, length: 16, enumType: Creditor::class)]
    private Creditor $creditor;

    #[ORM\Column(name: 'property_id', type: Types::GUID)]
    private string $propertyId;

    #[ORM\Column(name: 'creditor_party_ids', type: Types::STRING, length: 400)]
    private string $creditorPartyIds;

    #[ORM\Column(type: Types::INTEGER)]
    private int $number;

    public function __construct(string $partyId, int $partyNumber, CreditorIdentity $creditor, int $number)
    {
        $this->partyId = $partyId;
        $this->partyNumber = $partyNumber;
        $this->creditor = $creditor->role;
        $this->propertyId = $creditor->propertyId;
        $this->creditorPartyIds = $creditor->partyIds;
        $this->number = $number;
    }

    public function partyId(): string
    {
        return $this->partyId;
    }

    public function partyNumber(): int
    {
        return $this->partyNumber;
    }

    public function creditor(): Creditor
    {
        return $this->creditor;
    }

    public function creditorIdentity(): CreditorIdentity
    {
        return CreditorIdentity::stored($this->creditor, $this->propertyId, $this->creditorPartyIds);
    }

    public function propertyId(): string
    {
        return $this->propertyId;
    }

    public function number(): int
    {
        return $this->number;
    }

    public function toString(): string
    {
        return \sprintf('MA-%d-%d', $this->partyNumber, $this->number);
    }
}
