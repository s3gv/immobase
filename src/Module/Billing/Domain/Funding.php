<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Billing\Domain;

use App\Module\Finance\Contract\Interval;
use App\Shared\Money\Money;
use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Woher das Geld kommt.
 *
 * Vier Wege, und sie schliessen sich nicht aus: ein Dach wird aus der
 * Ruecklage bezahlt, eine Photovoltaikanlage aus einer Sonderumlage und einem
 * Darlehen, ein neuer Aufzug aus drei Jahren Ansparen.
 *
 * Was sie gemeinsam haben: sie muessen zusammen den Bedarf decken. Eine Luecke
 * ist kein Rundungsfehler, sondern eine Finanzierung, die nicht traegt — und
 * sie haelt die Herausgabe auf.
 *
 * Die **Ruecklage** traegt nur Erhaltungsmassnahmen: sie ist zweckgebunden
 * (§ 19 Abs. 2 Nr. 4 WEG). Fuer eine bauliche Veraenderung ist die Entnahme
 * streitig, und die Praxis nimmt die Sonderumlage.
 */
#[ORM\Embeddable]
final class Funding
{
    #[ORM\Column(name: 'from_reserve', type: Types::BIGINT)]
    private int $reserve;

    #[ORM\Column(name: 'levy_amount', type: Types::BIGINT)]
    private int $levy;

    /** Der erste Faelligkeitstag — der Beschluss muss ihn nennen. */
    #[ORM\Column(name: 'levy_due_on', type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?DateTimeImmutable $levyDueOn;

    #[ORM\Column(name: 'levy_parts', type: Types::SMALLINT)]
    private int $levyParts;

    #[ORM\Column(name: 'levy_interval', type: Types::STRING, length: 16, enumType: Interval::class)]
    private Interval $levyInterval;

    #[ORM\Column(name: 'levy_purpose', type: Types::STRING, length: 16, enumType: LevyPurpose::class)]
    private LevyPurpose $levyPurpose;

    #[ORM\Column(name: 'saving_amount', type: Types::BIGINT)]
    private int $saving;

    #[ORM\Column(name: 'saving_years', type: Types::SMALLINT)]
    private int $savingYears;

    #[ORM\Column(name: 'saving_from', type: Types::SMALLINT)]
    private int $savingFrom;

    #[ORM\Column(name: 'loan_amount', type: Types::BIGINT)]
    private int $loan;

    /** In Basispunkten: 4,20 % sind 420. */
    #[ORM\Column(name: 'loan_rate_bps', type: Types::INTEGER)]
    private int $loanRateBps;

    /** Entweder die Rate oder die Laufzeit — was bekannt ist, sagt die Bank. */
    #[ORM\Column(name: 'loan_payment', type: Types::BIGINT, nullable: true)]
    private ?int $loanPayment;

    #[ORM\Column(name: 'loan_months', type: Types::SMALLINT, nullable: true)]
    private ?int $loanMonths;

    private function __construct()
    {
        $this->reserve = 0;
        $this->levy = 0;
        $this->levyDueOn = null;
        $this->levyParts = 1;
        $this->levyInterval = Interval::Once;
        $this->levyPurpose = LevyPurpose::ForTheMeasure;
        $this->saving = 0;
        $this->savingYears = 0;
        $this->savingFrom = 0;
        $this->loan = 0;
        $this->loanRateBps = 0;
        $this->loanPayment = null;
        $this->loanMonths = null;
    }

    public static function none(): self
    {
        return new self();
    }

    public function fromTheReserve(Money $amount): self
    {
        $next = clone $this;
        $next->reserve = max(0, $amount->cents());

        return $next;
    }

    public function byLevy(
        Money $amount,
        ?DateTimeImmutable $dueOn,
        int $parts,
        Interval $interval,
        LevyPurpose $purpose = LevyPurpose::ForTheMeasure,
    ): self {
        $next = clone $this;
        $next->levy = max(0, $amount->cents());
        $next->levyDueOn = $dueOn;
        $next->levyParts = max(1, min(24, $parts));
        $next->levyInterval = $interval;
        $next->levyPurpose = $purpose;

        return $next;
    }

    /** Derselbe Weg oder ein anderer — was die Massnahme zulaesst, sagt {@see Measure::levyMay()}. */
    public function levyGoing(LevyPurpose $purpose): self
    {
        if ($purpose === $this->levyPurpose) {
            return $this;
        }

        $next = clone $this;
        $next->levyPurpose = $purpose;

        return $next;
    }

    public function bySaving(Money $amount, int $years, int $from): self
    {
        $next = clone $this;
        $next->saving = max(0, $amount->cents());
        $next->savingYears = max(0, min(20, $years));
        $next->savingFrom = $from;

        return $next;
    }

    public function byLoan(Money $amount, int $rateBps, ?Money $payment, ?int $months): self
    {
        $next = clone $this;
        $next->loan = max(0, $amount->cents());
        $next->loanRateBps = max(0, min(3000, $rateBps));
        $next->loanPayment = $payment?->cents();
        $next->loanMonths = $months;

        return $next;
    }

    public function reserve(): Money
    {
        return Money::fromCents($this->reserve);
    }

    public function levy(): Money
    {
        return Money::fromCents($this->levy);
    }

    public function levyDueOn(): ?DateTimeImmutable
    {
        return $this->levyDueOn;
    }

    public function levyPurpose(): LevyPurpose
    {
        return $this->levyPurpose;
    }

    public function levyParts(): int
    {
        return $this->levyParts;
    }

    public function levyInterval(): Interval
    {
        return $this->levyInterval;
    }

    public function saving(): Money
    {
        return Money::fromCents($this->saving);
    }

    public function savingYears(): int
    {
        return $this->savingYears;
    }

    public function savingFrom(): int
    {
        return $this->savingFrom;
    }

    public function loan(): Money
    {
        return Money::fromCents($this->loan);
    }

    public function loanRateBps(): int
    {
        return $this->loanRateBps;
    }

    public function loanPayment(): ?Money
    {
        return null === $this->loanPayment ? null : Money::fromCents($this->loanPayment);
    }

    public function loanMonths(): ?int
    {
        return $this->loanMonths;
    }

    /** Was die vier Wege zusammen aufbringen. */
    public function total(): Money
    {
        return Money::fromCents($this->reserve + $this->levy + $this->saving + $this->loan);
    }
}
