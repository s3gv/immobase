<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Billing\Domain;

use App\Shared\Identity\Uuid;
use App\Shared\Money\Money;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Eine offene Forderung der Gemeinschaft gegen eine Einheit — eingefroren.
 *
 * **Mit Nummer, ohne Namen.** Jeder Eigentuemer traegt das Ausfallrisiko mit
 * und darf wissen, wie hoch es ist; wer wissen will, wen es betrifft, kann es
 * sich herleiten. Der Bericht stellt ihn aber nicht an den Pranger — § 28
 * Abs. 4 WEG verlangt eine Aufstellung des Vermoegens und keine Liste von
 * Schuldnern.
 *
 * Eingefroren, weil eine Zahlung nach der Herausgabe den Bericht sonst
 * rueckwirkend aenderte. Sie aendert ihn nicht: sie kommt im naechsten vor.
 */
#[ORM\Entity]
#[ORM\Table(name: 'billing_asset_claim')]
class AssetClaim
{
    #[ORM\Id]
    #[ORM\Column(type: Types::GUID)]
    private string $id;

    #[ORM\ManyToOne(targetEntity: AssetReport::class, inversedBy: 'claims')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private AssetReport $report;

    #[ORM\Column(name: 'unit_number', type: Types::INTEGER)]
    private int $unitNumber;

    #[ORM\Column(type: Types::BIGINT)]
    private int $amount;

    /** Das aelteste Wirtschaftsjahr, in dem etwas offen blieb. */
    #[ORM\Column(type: Types::SMALLINT)]
    private int $since;

    public function __construct(AssetReport $report, int $unitNumber, Money $amount, int $since)
    {
        $this->id = Uuid::v4();
        $this->report = $report;
        $this->unitNumber = $unitNumber;
        $this->amount = $amount->cents();
        $this->since = $since;
        $report->holdClaim($this);
    }

    public function unitNumber(): int
    {
        return $this->unitNumber;
    }

    public function amount(): Money
    {
        return Money::fromCents($this->amount);
    }

    public function since(): int
    {
        return $this->since;
    }

    public function asReported(): ReportedClaim
    {
        return new ReportedClaim($this->unitNumber, $this->amount(), $this->since);
    }
}
