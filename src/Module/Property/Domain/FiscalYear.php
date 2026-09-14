<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Property\Domain;

use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use InvalidArgumentException;

/**
 * Wann das Wirtschaftsjahr dieses Objekts beginnt.
 *
 * Tag und Monat, nicht von–bis: ein festes Von–Bis muesste jedes Jahr
 * angefasst werden, und die Abrechnung des Vorjahres braeuchte dann den
 * ueberschriebenen Stand. So steht die Regel einmal da und gilt fuer jedes
 * Jahr — Kalenderjahr wie abweichendes Wirtschaftsjahr.
 *
 * Der Tag muss es in jedem Monat geben, den es trifft. „Ab dem 31." waere in
 * elf von zwoelf Faellen eine Frage ohne Antwort, und der 29. Februar eine,
 * die nur alle vier Jahre eine hat.
 */
#[ORM\Embeddable]
final class FiscalYear
{
    /** Der letzte Tag, den jeder Monat hat. */
    private const int LAST_SAFE_DAY = 28;

    #[ORM\Column(name: 'fiscal_year_month', type: Types::SMALLINT)]
    private int $month;

    #[ORM\Column(name: 'fiscal_year_day', type: Types::SMALLINT)]
    private int $day;

    private function __construct(int $month, int $day)
    {
        $this->month = $month;
        $this->day = $day;
    }

    /** Das Kalenderjahr — was in den meisten Verträgen steht. */
    public static function calendar(): self
    {
        return new self(1, 1);
    }

    /**
     * @throws InvalidArgumentException
     */
    public static function beginningOn(int $day, int $month): self
    {
        if ($month < 1 || $month > 12) {
            throw new InvalidArgumentException('Den Monat gibt es nicht.');
        }

        if ($day < 1 || $day > self::LAST_SAFE_DAY) {
            throw new InvalidArgumentException('Ein Wirtschaftsjahr beginnt an einem Tag, den es in jedem Jahr gibt — dem 1. bis 28.');
        }

        return new self($month, $day);
    }

    public function month(): int
    {
        return $this->month;
    }

    public function day(): int
    {
        return $this->day;
    }

    public function isCalendarYear(): bool
    {
        return 1 === $this->month && 1 === $this->day;
    }

    /** Der erste Tag des Wirtschaftsjahres, das in diesem Kalenderjahr beginnt. */
    public function beginsIn(int $year): DateTimeImmutable
    {
        return new DateTimeImmutable(\sprintf('%04d-%02d-%02d', $year, $this->month, $this->day));
    }

    /** Und der letzte — der Tag vor dem naechsten Beginn. */
    public function endsIn(int $year): DateTimeImmutable
    {
        return $this->beginsIn($year + 1)->modify('-1 day');
    }

    /**
     * Zu welchem Wirtschaftsjahr dieser Tag gehoert.
     *
     * Benannt wird ein Wirtschaftsjahr nach dem Jahr, in dem es beginnt: der
     * 30. Juni 2027 liegt bei einem Beginn am 1. Juli im Wirtschaftsjahr
     * 2026.
     */
    public function yearOf(DateTimeImmutable $day): int
    {
        $year = (int) $day->format('Y');

        return $day < $this->beginsIn($year) ? $year - 1 : $year;
    }
}
