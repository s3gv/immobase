<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Finance\Domain;

use App\Module\Finance\Contract\Interval;
use App\Shared\Identity\Uuid;
use App\Shared\Money\Money;
use App\Shared\Text\Trimmed;
use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use InvalidArgumentException;

/**
 * Eine Stufe der Hausgeld-Vorauszahlung: ab wann zahlt eine Einheit was.
 *
 * Dieselbe Form wie die Mietstaffel — Betrag ab Datum —, und aus demselben
 * Grund: was heute gilt, ist die letzte Stufe mit Datum <= heute, und was
 * frueher galt, bleibt lesbar.
 *
 * Warum hier Stufen und bei den Kosten Jahreswerte: eine Vorauszahlung ist
 * ein laufender Betrag, der sich an einem Datum aendert; eine Kostenposition
 * ist eine Jahressumme. Zwei verschiedene Sachen, zwei passende Formen.
 *
 * Das Hausgeld zahlt der Eigentuemer. Die Nebenkosten-Vorauszahlung des
 * Mieters steht im Mietvertrag und damit in der Mietstaffel — sie wird von
 * dort gelesen und nicht hier gefuehrt.
 *
 * Woher eine Stufe kommt — aus einem beschlossenen Wirtschaftsplan oder von
 * Hand —, steht an ihr: {@see Origin}.
 */
#[ORM\Entity]
#[ORM\Table(name: 'finance_house_money')]
#[ORM\UniqueConstraint(name: 'finance_house_money_once', columns: ['unit_id', 'starts_on'])]
class HouseMoney
{
    #[ORM\Id]
    #[ORM\Column(type: Types::GUID)]
    private string $id;

    #[ORM\Column(name: 'unit_id', type: Types::GUID)]
    private string $unitId;

    #[ORM\Column(name: 'starts_on', type: Types::DATE_IMMUTABLE)]
    private DateTimeImmutable $startsOn;

    #[ORM\Column(type: Types::BIGINT)]
    private int $amount;

    #[ORM\Column(name: 'pay_interval', type: Types::STRING, length: 16, enumType: Interval::class)]
    private Interval $interval;

    #[ORM\Column(type: Types::TEXT)]
    private string $note = '';

    #[ORM\Embedded(class: Origin::class, columnPrefix: false)]
    private Origin $origin;

    public function __construct(string $unitId, DateTimeImmutable $startsOn, Money $amount, Interval $interval)
    {
        $this->id = Uuid::v4();
        $this->unitId = $unitId;
        $this->startsOn = $startsOn;
        $this->interval = $interval;
        $this->origin = Origin::byHand();
        $this->charge($amount, $interval);
    }

    public function id(): string
    {
        return $this->id;
    }

    public function unitId(): string
    {
        return $this->unitId;
    }

    public function startsOn(): DateTimeImmutable
    {
        return $this->startsOn;
    }

    public function moveTo(DateTimeImmutable $startsOn): void
    {
        $this->startsOn = $startsOn;
    }

    public function amount(): Money
    {
        return Money::fromCents($this->amount);
    }

    public function interval(): Interval
    {
        return $this->interval;
    }

    /**
     * @throws InvalidArgumentException
     */
    public function charge(Money $amount, Interval $interval): void
    {
        if ($amount->isNegative()) {
            throw new InvalidArgumentException('Eine Vorauszahlung kann nicht negativ sein.');
        }

        $this->amount = $amount->cents();
        $this->interval = $interval;
    }

    public function origin(): Origin
    {
        return $this->origin;
    }

    /** Sie kommt aus diesem Wirtschaftsplan — und ist damit beschlossen. */
    public function decidedBy(string $planReference): void
    {
        $this->origin = Origin::fromPlan($planReference);
    }

    /** Jemand hat den Betrag angefasst; die Herkunft bleibt trotzdem lesbar. */
    public function changedByHand(): void
    {
        $this->origin = $this->origin->touched();
    }

    public function note(): string
    {
        return $this->note;
    }

    public function noteThat(string $note): void
    {
        $this->note = Trimmed::orNull($note) ?? '';
    }
}
