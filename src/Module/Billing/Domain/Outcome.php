<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Billing\Domain;

use App\Shared\Money\Money;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Was unter dem Strich steht.
 *
 * Kosten, Vorauszahlungen — und bei einer Korrektur, was davon schon
 * abgerechnet war. Die drei stehen zusammen, weil die Zahl, auf die es
 * ankommt, aus allen dreien entsteht: eine Korrektur weist nur die Differenz
 * aus, sonst stuende dieselbe Forderung zweimal in der Welt.
 */
#[ORM\Embeddable]
final class Outcome
{
    #[ORM\Column(type: Types::BIGINT)]
    private int $costs;

    #[ORM\Column(type: Types::BIGINT)]
    private int $advances;

    /** Die Umsatzsteuer auf die Kosten — null ohne Option. */
    #[ORM\Column(type: Types::BIGINT)]
    private int $tax;

    /** Die Umsatzsteuer, die in den gezahlten Vorauszahlungen steckt. */
    #[ORM\Column(name: 'advances_tax', type: Types::BIGINT)]
    private int $advancesTax;

    /** Nur bei Korrekturen gesetzt. */
    #[ORM\Column(name: 'settled_balance', type: Types::BIGINT, nullable: true)]
    private ?int $settled;

    private function __construct(int $costs, int $advances, ?int $settled, int $tax = 0, int $advancesTax = 0)
    {
        $this->costs = $costs;
        $this->advances = $advances;
        $this->settled = $settled;
        $this->tax = $tax;
        $this->advancesTax = $advancesTax;
    }

    public static function nothing(): self
    {
        return new self(0, 0, null);
    }

    public function of(Money $costs, Money $advances, ?Money $tax = null, ?Money $advancesTax = null): self
    {
        return new self(
            $costs->cents(),
            $advances->cents(),
            $this->settled,
            $tax?->cents() ?? 0,
            $advancesTax?->cents() ?? 0,
        );
    }

    /** Eine Korrektur weiss, was vor ihr schon berechnet wurde. */
    public function after(Money $settled): self
    {
        return new self($this->costs, $this->advances, $settled->cents(), $this->tax, $this->advancesTax);
    }

    public function costs(): Money
    {
        return Money::fromCents($this->costs);
    }

    public function advances(): Money
    {
        return Money::fromCents($this->advances);
    }

    public function tax(): Money
    {
        return Money::fromCents($this->tax);
    }

    public function advancesTax(): Money
    {
        return Money::fromCents($this->advancesTax);
    }

    /** Die Kosten samt Umsatzsteuer — ohne Option dieselbe Zahl wie die Kosten. */
    public function gross(): Money
    {
        return $this->costs()->plus($this->tax());
    }

    /** Was gerechnet wurde, bevor Bereits-Abgerechnetes abgezogen ist. */
    public function result(): Money
    {
        return $this->gross()->minus($this->advances());
    }

    public function settled(): ?Money
    {
        return null === $this->settled ? null : Money::fromCents($this->settled);
    }

    /** Nachzahlung, wenn positiv; Guthaben, wenn negativ. */
    public function balance(): Money
    {
        return $this->result()->minus($this->settled() ?? Money::zero());
    }

    public function isCorrection(): bool
    {
        return null !== $this->settled;
    }
}
