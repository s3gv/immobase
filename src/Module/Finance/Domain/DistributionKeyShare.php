<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Finance\Domain;

use App\Shared\Identity\Uuid;
use App\Shared\Number\Decimals;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use InvalidArgumentException;

/**
 * Der feste Anteil einer Einheit an einem Verteilerschluessel.
 *
 * Nur bei festen Schluesseln. Die Summe wird angezeigt und blockiert nicht —
 * dieselbe Entscheidung wie bei den Miteigentumsanteilen: wer gerade erst
 * anfaengt zu erfassen, hat zwischendurch immer eine falsche Summe, und eine
 * Sperre dagegen macht das Erfassen unmoeglich.
 */
#[ORM\Entity]
#[ORM\Table(name: 'finance_distribution_key_share')]
#[ORM\UniqueConstraint(name: 'finance_key_share_once', columns: ['key_id', 'unit_id'])]
class DistributionKeyShare
{
    #[ORM\Id]
    #[ORM\Column(type: Types::GUID)]
    private string $id;

    #[ORM\ManyToOne(targetEntity: DistributionKey::class, inversedBy: 'shares')]
    #[ORM\JoinColumn(name: 'key_id', nullable: false, onDelete: 'CASCADE')]
    private DistributionKey $key;

    #[ORM\Column(name: 'unit_id', type: Types::GUID)]
    private string $unitId;

    #[ORM\Column(type: Types::DECIMAL, precision: 12, scale: 4)]
    private string $share;

    public function __construct(DistributionKey $key, string $unitId, string $share)
    {
        $this->id = Uuid::v4();
        $this->key = $key;
        $this->unitId = $unitId;
        $this->hold($share);

        $key->add($this);
    }

    public function id(): string
    {
        return $this->id;
    }

    public function key(): DistributionKey
    {
        return $this->key;
    }

    public function unitId(): string
    {
        return $this->unitId;
    }

    public function share(): string
    {
        return $this->share;
    }

    /**
     * @throws InvalidArgumentException
     */
    public function hold(string $share): void
    {
        // Vier Nachkommastellen sind erlaubt — die Tausenderregel wuerde
        // „1,0000" sonst nicht einmal treffen, „1,000" aber schon.
        $normalised = Decimals::normalise($share, thirdDecimalCounts: true);

        if (1 !== preg_match('/^\d{1,8}(\.\d{1,4})?$/D', $normalised)) {
            throw new InvalidArgumentException('Ein Anteil ist eine Zahl ab null mit höchstens vier Nachkommastellen.');
        }

        $this->share = $normalised;
    }
}
