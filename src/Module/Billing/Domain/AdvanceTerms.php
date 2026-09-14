<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Billing\Domain;

use App\Module\Finance\Contract\Interval;
use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Wie die beschlossenen Vorschuesse zu zahlen sind.
 *
 * Intervall und erster Faelligkeitstag gehoeren zusammen: „monatlich" allein
 * sagt nicht, ab wann, und ein Datum allein nicht, wie oft.
 *
 * § 28 Abs. 3 WEG laesst die Gemeinschaft ueber die Faelligkeit beschliessen.
 * Ohne Beschluss ist der Vorschuss zum Monatsersten faellig — das ist die
 * Vorbelegung, und sie ist der Beginn des Wirtschaftsjahres.
 */
#[ORM\Embeddable]
final class AdvanceTerms
{
    #[ORM\Column(name: 'pay_interval', type: Types::STRING, length: 16, enumType: Interval::class)]
    private Interval $interval;

    #[ORM\Column(name: 'first_due_on', type: Types::DATE_IMMUTABLE)]
    private DateTimeImmutable $firstDueOn;

    private function __construct(Interval $interval, DateTimeImmutable $firstDueOn)
    {
        $this->interval = $interval;
        $this->firstDueOn = $firstDueOn;
    }

    /** Monatlich, ab dem ersten Tag des Wirtschaftsjahres. */
    public static function monthlyFrom(DateTimeImmutable $firstDueOn): self
    {
        return new self(Interval::Monthly, $firstDueOn);
    }

    public static function of(Interval $interval, DateTimeImmutable $firstDueOn): self
    {
        return new self($interval, $firstDueOn);
    }

    public function interval(): Interval
    {
        return $this->interval;
    }

    public function firstDueOn(): DateTimeImmutable
    {
        return $this->firstDueOn;
    }

    /** Wie viele Zahlungen das Jahr ergibt. */
    public function timesAYear(): int
    {
        return $this->interval->timesAYear();
    }
}
