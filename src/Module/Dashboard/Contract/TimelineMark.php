<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Dashboard\Contract;

/**
 * Eine Markierung auf dem Zeitstrahl — und was an ihr haengt.
 *
 * **Eine Markierung kann mehrere Termine tragen.** Ein Jahr auf tausend
 * Bildpunkten gibt jedem Tag knapp drei davon; zwei Termine derselben Woche
 * saessen uebereinander, und der eine verdeckte den anderen. Was zu nah
 * beieinander liegt, wird darum zu einer Markierung zusammengefasst, und die
 * Liste darin nennt sie alle.
 *
 * `at` ist der Ort auf dem Balken in Prozent — gerechnet wird er einmal, hier,
 * und nicht in der Vorlage: eine Vorlage, die Prozentwerte ausrechnet, ist
 * eine Vorlage, die man nicht pruefen kann.
 */
final readonly class TimelineMark
{
    /**
     * @param list<TimelineEntry> $entries die fruehesten zuerst
     */
    public function __construct(
        public float $at,
        public array $entries,
    ) {
    }

    public function count(): int
    {
        return \count($this->entries);
    }
}
