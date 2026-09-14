<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Finance\Domain;

use App\Module\Finance\Contract\Interval;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use InvalidArgumentException;

/**
 * Wann eine Kostenposition faellig wird.
 *
 * Tag und Intervall gehoeren zusammen: „zum dritten" allein sagt nichts,
 * „monatlich" auch nicht. Der Monat kommt dazu, wo das Intervall ihn braucht
 * — bei jaehrlich und einmalig.
 *
 * Der Tag muss es in jedem Monat geben, aus demselben Grund wie beim
 * Wirtschaftsjahr: „zum 31." waere in elf von zwoelf Monaten eine Frage ohne
 * Antwort.
 */
#[ORM\Embeddable]
final class DueDate
{
    private const int LAST_SAFE_DAY = 28;

    #[ORM\Column(name: 'due_day', type: Types::SMALLINT)]
    private int $day;

    #[ORM\Column(name: 'due_month', type: Types::SMALLINT, nullable: true)]
    private ?int $month;

    #[ORM\Column(name: 'due_interval', type: Types::STRING, length: 16, enumType: Interval::class)]
    private Interval $interval;

    private function __construct(int $day, ?int $month, Interval $interval)
    {
        $this->day = $day;
        $this->month = $month;
        $this->interval = $interval;
    }

    /** Zum Monatsersten, jeden Monat — was am haeufigsten vorkommt. */
    public static function monthly(): self
    {
        return new self(1, null, Interval::Monthly);
    }

    /**
     * @throws InvalidArgumentException
     */
    public static function of(int $day, ?int $month, Interval $interval): self
    {
        if ($day < 1 || $day > self::LAST_SAFE_DAY) {
            throw new InvalidArgumentException('Der Fälligkeitstag muss zwischen 1 und 28 liegen.');
        }

        if (!$interval->needsAMonth()) {
            return new self($day, null, $interval);
        }

        if (null === $month || $month < 1 || $month > 12) {
            throw new InvalidArgumentException('Zu einer jährlichen Fälligkeit gehört ein Monat.');
        }

        return new self($day, $month, $interval);
    }

    public function day(): int
    {
        return $this->day;
    }

    public function month(): ?int
    {
        return $this->month;
    }

    public function interval(): Interval
    {
        return $this->interval;
    }
}
