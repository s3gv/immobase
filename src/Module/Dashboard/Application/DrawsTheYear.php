<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Dashboard\Application;

use App\Module\Dashboard\Contract\TimelineEntry;
use App\Module\Dashboard\Contract\TimelineMark;
use App\Module\Dashboard\Contract\YearTimeline;
use App\Module\Dashboard\Domain\Reminder;
use App\Module\Dashboard\Domain\ReminderRepository;
use DateTimeImmutable;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Das laufende Jahr als Balken, mit allem, was darauf steht.
 *
 * **Das Kalenderjahr und kein rollendes Fenster.** Es passt zum
 * Abrechnungsjahr, und der Balken sieht im Dezember anders aus als im Januar
 * — das ist die Auskunft, um die es geht. Ein rollendes Fenster haette den
 * Heute-Punkt immer an derselben Stelle und sagte damit nichts mehr.
 *
 * Gerechnet wird hier und nicht in der Vorlage: Schaltjahre, Wochengrenzen
 * und das Zusammenfassen naher Termine sind Regeln, und Regeln gehoeren
 * dorthin, wo man sie pruefen kann.
 */
final readonly class DrawsTheYear
{
    /**
     * Naeher als das stehen zwei Markierungen nicht nebeneinander.
     *
     * Ein Jahr auf tausend Bildpunkten gibt jedem Tag knapp drei davon. Ein
     * Prozent sind rund zehn — genug, dass zwei Punkte als zwei zu erkennen
     * sind, und wenig genug, dass ein Termin nicht sichtbar verrutscht.
     */
    private const float CLOSEST = 1.0;

    public function __construct(
        private ReminderRepository $reminders,
        private WhoIsHere $who,
        private ClockInterface $clock,
        private TranslatorInterface $translator,
    ) {
    }

    public function forThisYear(): YearTimeline
    {
        $now = $this->clock->now();
        $year = (int) $now->format('Y');
        $from = new DateTimeImmutable($year.'-01-01 00:00:00');
        $to = new DateTimeImmutable($year.'-12-31 23:59:59');

        return new YearTimeline(
            year: $year,
            today: $now,
            at: self::positionOf($now, $from, $to),
            weeks: self::weeksIn($from, $to),
            months: $this->monthsIn($year, $from, $to),
            marks: self::gathered($this->entriesIn($from, $to), $from, $to),
        );
    }

    /**
     * Wo ein Zeitpunkt auf dem Balken sitzt, in Prozent.
     *
     * Ueber die Sekunden und nicht ueber die Tage: so stimmt es auch im
     * Schaltjahr, und ein Termin am Nachmittag sitzt weiter rechts als einer
     * am Morgen.
     */
    private static function positionOf(DateTimeImmutable $at, DateTimeImmutable $from, DateTimeImmutable $to): float
    {
        $span = $to->getTimestamp() - $from->getTimestamp();
        $into = $at->getTimestamp() - $from->getTimestamp();

        return max(0.0, min(100.0, $into / $span * 100.0));
    }

    /**
     * Die Wochengrenzen — jeder Montag des Jahres.
     *
     * Sie sind die Unterteilung im Balken, ganz schwach: sie sollen ein
     * Gefuehl fuer den Abstand geben und nichts behaupten.
     *
     * @return list<float>
     */
    private static function weeksIn(DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        $monday = $from->modify('monday this week');

        if ($monday < $from) {
            $monday = $monday->modify('+1 week');
        }

        $weeks = [];

        for ($at = $monday; $at <= $to; $at = $at->modify('+1 week')) {
            $weeks[] = self::positionOf($at, $from, $to);
        }

        return $weeks;
    }

    /**
     * @return list<array{label: string, at: float}>
     */
    private function monthsIn(int $year, DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        $months = [];

        for ($month = 1; $month <= 12; ++$month) {
            $first = new DateTimeImmutable(\sprintf('%d-%02d-01 00:00:00', $year, $month));
            $months[] = [
                'label' => $this->translator->trans('month.short.'.$month),
                'at' => self::positionOf($first, $from, $to),
            ];
        }

        return $months;
    }

    /**
     * @return list<TimelineEntry>
     */
    private function entriesIn(DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        $today = $this->clock->now();

        return array_map(
            static fn (Reminder $reminder): TimelineEntry => new TimelineEntry(
                id: $reminder->id(),
                at: $reminder->dueAt(),
                subject: $reminder->subject(),
                about: $reminder->note(),
                isPast: $reminder->dueAt() < $today,
            ),
            $this->reminders->between(($this->who)()->id, $from, $to),
        );
    }

    /**
     * Was zu nah beieinander liegt, wird eine Markierung.
     *
     * Die Termine kommen schon nach Zeit sortiert aus der Ablage; deshalb
     * genuegt ein Durchlauf. Der Ort einer Markierung ist der ihres ersten
     * Termins — nicht der Mittelwert: der laege zwischen zwei Tagen, und
     * niemand hat an diesem Tag etwas vor.
     *
     * @param list<TimelineEntry> $entries
     *
     * @return list<TimelineMark>
     */
    private static function gathered(array $entries, DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        $marks = [];
        $together = [];
        $at = 0.0;

        foreach ($entries as $entry) {
            $here = self::positionOf($entry->at, $from, $to);

            if ([] !== $together && $here - $at > self::CLOSEST) {
                $marks[] = new TimelineMark($at, $together);
                $together = [];
            }

            $at = [] === $together ? $here : $at;
            $together[] = $entry;
        }

        if ([] !== $together) {
            $marks[] = new TimelineMark($at, $together);
        }

        return $marks;
    }
}
