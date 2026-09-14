<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Billing\Domain;

use App\Shared\Identity\Uuid;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Eine Einheit, die zugestimmt hat.
 *
 * Der Grund, warum das ueberhaupt festgehalten wird, steht in § 21 Abs. 3
 * WEG: fehlt der baulichen Veraenderung die doppelt qualifizierte Mehrheit,
 * tragen die Kosten **nur die zustimmenden** Eigentuemer — und nur sie duerfen
 * nutzen (Abs. 4). Wer zugestimmt hat, ist dann keine Nebensache, sondern der
 * Verteilerkreis.
 *
 * Haengt am Budget wie {@see PlanSource} am Plan und nicht als Sammlung darin:
 * es ist eine Menge von Kennungen, die einmal nach der Versammlung geschrieben
 * wird.
 */
#[ORM\Entity]
#[ORM\Table(name: 'billing_budget_approval')]
#[ORM\UniqueConstraint(name: 'billing_budget_approval_unit', columns: ['budget_id', 'unit_id'])]
class BudgetApproval
{
    #[ORM\Id]
    #[ORM\Column(type: Types::GUID)]
    private string $id;

    #[ORM\Column(name: 'budget_id', type: Types::GUID)]
    private string $budgetId;

    #[ORM\Column(name: 'unit_id', type: Types::GUID)]
    private string $unitId;

    public function __construct(string $budgetId, string $unitId)
    {
        $this->id = Uuid::v4();
        $this->budgetId = $budgetId;
        $this->unitId = $unitId;
    }

    public function budgetId(): string
    {
        return $this->budgetId;
    }

    public function unitId(): string
    {
        return $this->unitId;
    }
}
