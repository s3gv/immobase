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
 * Ein Schreiben an einen Empfaenger — eingefroren.
 *
 * Vier Zahlen, und jede beantwortet eine andere Frage: **Anteil** ist, was die
 * Einheit an der Massnahme traegt; **Sonderumlage**, was sie davon jetzt
 * zahlt; **Zufuehrung**, was sie dafuer jaehrlich mehr anspart;
 * **Darlehensrate**, was monatlich auf sie entfaellt.
 *
 * Sie werden einzeln verteilt und nicht auseinander abgeleitet: aus einem
 * Anteil anteilig zu rechnen hiesse, Rundungsreste weiterzureichen, bis die
 * Summe der Schreiben nicht mehr der Beschluss ist.
 */
#[ORM\Entity]
#[ORM\Table(name: 'billing_budget_document')]
#[ORM\Index(name: 'billing_budget_document_unit', columns: ['unit_id'])]
class BudgetDocument
{
    #[ORM\Id]
    #[ORM\Column(type: Types::GUID)]
    private string $id;

    #[ORM\ManyToOne(targetEntity: Budget::class, inversedBy: 'documents')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Budget $budget;

    #[ORM\Column(name: 'unit_id', type: Types::GUID)]
    private string $unitId;

    #[ORM\Column(name: 'unit_number', type: Types::INTEGER)]
    private int $unitNumber;

    #[ORM\Column(name: 'unit_label', type: Types::STRING, length: 200)]
    private string $unitLabel;

    #[ORM\Embedded(class: Recipient::class, columnPrefix: false)]
    private Recipient $recipient;

    #[ORM\Column(name: 'share_amount', type: Types::BIGINT)]
    private int $share;

    #[ORM\Column(name: 'levy_share', type: Types::BIGINT)]
    private int $levy;

    #[ORM\Column(name: 'saving_share', type: Types::BIGINT)]
    private int $saving;

    #[ORM\Column(name: 'loan_share', type: Types::BIGINT)]
    private int $loanPayment;

    public function __construct(
        Budget $budget,
        string $unitId,
        int $unitNumber,
        string $unitLabel,
        string $recipientLabel,
        string $recipientAddress,
        Money $share,
        Money $levy,
        Money $saving,
        Money $loanPayment,
    ) {
        $this->id = Uuid::v4();
        $this->budget = $budget;
        $this->unitId = $unitId;
        $this->unitNumber = $unitNumber;
        $this->unitLabel = $unitLabel;
        $this->recipient = new Recipient($recipientLabel, $recipientAddress);
        $this->share = $share->cents();
        $this->levy = $levy->cents();
        $this->saving = $saving->cents();
        $this->loanPayment = $loanPayment->cents();
        $budget->hold($this);
    }

    public function id(): string
    {
        return $this->id;
    }

    public function unitId(): string
    {
        return $this->unitId;
    }

    public function unitNumber(): int
    {
        return $this->unitNumber;
    }

    public function unitLabel(): string
    {
        return $this->unitLabel;
    }

    public function recipient(): Recipient
    {
        return $this->recipient;
    }

    public function share(): Money
    {
        return Money::fromCents($this->share);
    }

    public function levy(): Money
    {
        return Money::fromCents($this->levy);
    }

    public function saving(): Money
    {
        return Money::fromCents($this->saving);
    }

    public function loanPayment(): Money
    {
        return Money::fromCents($this->loanPayment);
    }

    public function reference(): Reference
    {
        return BudgetReference::of($this->budget, $this->unitNumber);
    }
}
