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
 * Die Restschuld eines Darlehens am Stichtag — eingefroren.
 *
 * **Gerechnet statt getippt.** Die Restschuld steht im Tilgungsplan, und sie
 * ist an jedem Tag eine andere; als erfasste Verbindlichkeit waere sie eine
 * Zahl, die jemand jaehrlich abschreibt. Eingefroren wird sie trotzdem: nach
 * der Herausgabe darf eine Sondertilgung das zugestellte Schreiben nicht
 * mehr aendern.
 */
#[ORM\Entity]
#[ORM\Table(name: 'billing_asset_debt')]
class AssetDebt
{
    #[ORM\Id]
    #[ORM\Column(type: Types::GUID)]
    private string $id;

    #[ORM\ManyToOne(targetEntity: AssetReport::class, inversedBy: 'debts')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private AssetReport $report;

    /** Wie das Darlehen hiess — festgehalten, damit eine Umbenennung das Blatt nicht aendert. */
    #[ORM\Column(type: Types::STRING, length: 400)]
    private string $label;

    #[ORM\Column(type: Types::BIGINT)]
    private int $outstanding;

    /** Die Reihenfolge der Finanzen, festgehalten — sonst ordnete die Datenbank. */
    #[ORM\Column(type: Types::SMALLINT)]
    private int $ordering;

    public function __construct(AssetReport $report, int $ordering, string $label, Money $outstanding)
    {
        $this->id = Uuid::v4();
        $this->report = $report;
        $this->ordering = $ordering;
        $this->label = $label;
        $this->outstanding = $outstanding->cents();
        $report->holdDebt($this);
    }

    public function label(): string
    {
        return $this->label;
    }

    public function outstanding(): Money
    {
        return Money::fromCents($this->outstanding);
    }

    public function asReported(): ReportedDebt
    {
        return new ReportedDebt($this->label, $this->outstanding());
    }
}
