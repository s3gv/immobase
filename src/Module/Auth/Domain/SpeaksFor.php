<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Auth\Domain;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Fuer wen ein Konto spricht — und damit: ob es zum Portal gehoert.
 *
 * Niemand heisst Mitarbeiter. Jemand heisst Mieter oder Eigentuemer, und dann
 * ist es **kein** Mitarbeiter mit weniger Rechten, sondern ueberhaupt keiner:
 * es bekommt keine Rolle, kein Recht aus dem Katalog und steht in keiner
 * Benutzerliste.
 *
 * **Darum haengt der Portalzugang hieran und nicht an einer Rolle.** Eine
 * Rolle stuende in „Rollen und Rechte", liesse sich bearbeiten, und jemand
 * gaebe ihr eines Tages `parties.view` mit. Wofuer jemand spricht, laesst
 * sich nicht versehentlich erweitern.
 *
 * Eine schlichte Kennung ohne Fremdschluessel: `Auth` soll `Party` nicht
 * kennen muessen, um jemanden anzumelden. Wer die Partei dahinter braucht,
 * schlaegt sie dort nach, wo sie hingehoert.
 */
#[ORM\Embeddable]
final readonly class SpeaksFor
{
    #[ORM\Column(name: 'party_id', type: Types::GUID, nullable: true)]
    private ?string $partyId;

    private function __construct(?string $partyId)
    {
        $this->partyId = $partyId;
    }

    /** Ein Konto der Verwaltung. */
    public static function itself(): self
    {
        return new self(null);
    }

    public static function theParty(string $partyId): self
    {
        return new self($partyId);
    }

    public static function orItself(?string $partyId): self
    {
        return null === $partyId ? self::itself() : self::theParty($partyId);
    }

    public function partyId(): ?string
    {
        return $this->partyId;
    }

    public function isAParty(): bool
    {
        return null !== $this->partyId;
    }
}
