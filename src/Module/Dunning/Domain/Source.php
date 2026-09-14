<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Dunning\Domain;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Woher eine Forderung kommt und fuer wen sie besteht.
 *
 * Sie beantworten dieselbe Frage — worueber wird gemahnt und in wessen Namen:
 * der Glaeubiger, das Objekt und die Einheit, aus der die Forderung stammt,
 * und die Herkunft samt ihrer Kennung.
 *
 * **Der Glaeubiger steht vollstaendig da** ({@see CreditorIdentity}), und
 * beim Vermieter heisst das: mit den Eigentuemern der Einheit zum Tag der
 * Faelligkeit. Festgehalten, nicht nachgeschlagen — aus demselben Grund, aus
 * dem der Schuldner hier steht und nicht aus dem Mietvertrag von heute
 * gelesen wird: eine Forderung gehoert dem, dem sie bei ihrer Entstehung
 * gehoerte. Wer die Wohnung inzwischen verkauft hat, war damals der
 * Glaeubiger, und die Forderung ist mit dem Verkauf nicht mitgegangen.
 *
 * Die Kennung ist zusammen mit der Herkunft eindeutig: dieselbe Rate soll
 * nicht zweimal zur Forderung werden, sonst mahnt jemand sie zweimal.
 */
#[ORM\Embeddable]
final class Source
{
    #[ORM\Column(type: Types::STRING, length: 16, enumType: Creditor::class)]
    private Creditor $creditor;

    #[ORM\Column(name: 'property_id', type: Types::GUID)]
    private string $propertyId;

    /** Leer bei der Gemeinschaft — sie ist keine Partei, sondern das Objekt. */
    #[ORM\Column(name: 'creditor_party_ids', type: Types::STRING, length: 400)]
    private string $creditorPartyIds;

    #[ORM\Column(name: 'unit_id', type: Types::GUID, nullable: true)]
    private ?string $unitId;

    #[ORM\Column(type: Types::STRING, length: 16, enumType: ClaimOrigin::class)]
    private ClaimOrigin $origin;

    /**
     * Leer bei einer eingetragenen Forderung — und zwar **null**, nicht „".
     *
     * Zusammen mit der Herkunft ist die Kennung eindeutig: dieselbe Rate
     * soll nicht zweimal zur Forderung werden. Ein leerer Text waere fuer
     * die Datenbank ein Wert wie jeder andere, und dann gaebe es genau eine
     * eingetragene Forderung auf der ganzen Installation. Null ist in einem
     * eindeutigen Index jedes Mal ein anderes Nichts.
     */
    #[ORM\Column(name: 'origin_id', type: Types::STRING, length: 64, nullable: true)]
    private ?string $originId;

    private function __construct(
        CreditorIdentity $creditor,
        ?string $unitId,
        ClaimOrigin $origin,
        ?string $originId,
    ) {
        $this->creditor = $creditor->role;
        $this->propertyId = $creditor->propertyId;
        $this->creditorPartyIds = $creditor->partyIds;
        $this->unitId = $unitId;
        $this->origin = $origin;
        $this->originId = $originId;
    }

    /** Aus einer Vorauszahlung — ihre Kennung haelt sie auseinander. */
    public static function fromAnAdvance(CreditorIdentity $creditor, string $unitId, string $paymentId): self
    {
        return new self($creditor, $unitId, ClaimOrigin::Advance, $paymentId);
    }

    /** Von Hand eingetragen — ohne Kennung, weil es nichts gibt, worauf sie zeigte. */
    public static function entered(CreditorIdentity $creditor, ?string $unitId): self
    {
        return new self($creditor, $unitId, ClaimOrigin::Manual, null);
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

    public function unitId(): ?string
    {
        return $this->unitId;
    }

    public function origin(): ClaimOrigin
    {
        return $this->origin;
    }

    public function originId(): ?string
    {
        return $this->originId;
    }

    public function isFromAnAdvance(): bool
    {
        return ClaimOrigin::Advance === $this->origin;
    }
}
