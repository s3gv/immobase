<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Finance\Domain;

use App\Shared\Identity\Uuid;
use App\Shared\Text\Trimmed;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Eine Kostenart: wofuer Geld ausgegeben wird.
 *
 * Eine gepflegte Liste und kein Freitext. Freitext liesse dieselbe Kostenart
 * in drei Objekten dreimal anders heissen — „Muell", „Muellabfuhr",
 * „Abfallentsorgung" —, und die Abrechnung koennte nicht gruppieren.
 *
 * Vorbelegt mit den siebzehn Positionen aus § 2 BetrKV und den ueblichen
 * nicht umlagefaehigen. Die Umlagefaehigkeit steht hier, weil sie eine
 * Eigenschaft der Kostenart ist und keine der einzelnen Rechnung; an der
 * Position laesst sie sich uebersteuern, wenn ein Vertrag etwas ausschliesst.
 *
 * Systemarten lassen sich nicht loeschen: sie sind die gemeinsame Sprache,
 * in der spaeter jede Abrechnung gruppiert.
 */
#[ORM\Entity]
#[ORM\Table(name: 'finance_cost_kind')]
#[ORM\UniqueConstraint(name: 'finance_cost_kind_name', columns: ['name'])]
class CostKind
{
    #[ORM\Id]
    #[ORM\Column(type: Types::GUID)]
    private string $id;

    #[ORM\Column(type: Types::STRING, length: 120)]
    private string $name;

    #[ORM\Column(type: Types::BOOLEAN)]
    private bool $apportionable;

    /** Was mitgeliefert wird, bleibt: Systemarten sind nicht loeschbar. */
    #[ORM\Column(name: 'is_system', type: Types::BOOLEAN)]
    private bool $system;

    /** Die Reihenfolge der BetrKV — sie ist die gewohnte. */
    #[ORM\Column(type: Types::SMALLINT)]
    private int $ordering;

    public function __construct(string $name, bool $apportionable, int $ordering, bool $system = false)
    {
        $this->id = Uuid::v4();
        $this->system = $system;
        $this->ordering = $ordering;
        $this->rename($name, $apportionable);
    }

    public function id(): string
    {
        return $this->id;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function isApportionable(): bool
    {
        return $this->apportionable;
    }

    public function isSystem(): bool
    {
        return $this->system;
    }

    public function ordering(): int
    {
        return $this->ordering;
    }

    public function rename(string $name, bool $apportionable): void
    {
        $this->name = Trimmed::required($name, 'Die Bezeichnung');
        $this->apportionable = $apportionable;
    }
}
