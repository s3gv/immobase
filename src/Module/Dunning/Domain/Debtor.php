<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Dunning\Domain;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Wer schuldet — und was daraus folgt.
 *
 * Die Partei steht hier nicht allein: an der Frage, ob sie Unternehmer ist,
 * haengen beide Folgen des § 288 BGB — neun statt fuenf Prozentpunkte
 * (Abs. 2) und die Pauschale von vierzig Euro (Abs. 5). Vorbelegt wird sie
 * aus der Art der Partei, denn eine Firma ist nie Verbraucher;
 * uebersteuerbar bleibt sie, weil auch eine natuerliche Person Unternehmer
 * sein kann.
 *
 * Dass die Pauschale schon angesetzt wurde, steht ebenfalls hier: es gibt sie
 * einmal je Forderung und nicht je Schreiben.
 */
#[ORM\Embeddable]
final class Debtor
{
    #[ORM\Column(name: 'debtor_party_id', type: Types::GUID)]
    private string $partyId;

    #[ORM\Column(type: Types::BOOLEAN)]
    private bool $commercial;

    #[ORM\Column(name: 'flat_fee_claimed', type: Types::BOOLEAN)]
    private bool $flatFeeClaimed;

    private function __construct(string $partyId, bool $commercial, bool $flatFeeClaimed)
    {
        $this->partyId = $partyId;
        $this->commercial = $commercial;
        $this->flatFeeClaimed = $flatFeeClaimed;
    }

    public static function of(string $partyId, bool $commercial): self
    {
        return new self($partyId, $commercial, false);
    }

    public function partyId(): string
    {
        return $this->partyId;
    }

    public function isCommercial(): bool
    {
        return $this->commercial;
    }

    public function tradingAs(bool $commercial): self
    {
        return new self($this->partyId, $commercial, $this->flatFeeClaimed);
    }

    public function flatFeeClaimed(): bool
    {
        return $this->flatFeeClaimed;
    }

    public function withTheFlatFee(): self
    {
        return new self($this->partyId, $this->commercial, true);
    }
}
