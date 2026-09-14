<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Dashboard\Application;

use App\Module\Dashboard\Domain\Reminder;
use App\Module\Dashboard\Domain\ReminderRepository;
use App\Shared\Text\Trimmed;
use DateTimeImmutable;
use Symfony\Component\Clock\ClockInterface;

/**
 * Meine Erinnerungen — und ausdruecklich nur meine.
 *
 * Jede Frage an die Ablage geht mit meiner Kennung hinaus, und das Loeschen
 * fragt noch einmal nach, ob die Erinnerung mir gehoert. Die Abfrage holt
 * ohnehin nur die eigenen; die Kennung aus dem Formular ist aber Eingabe, und
 * Eingabe wird geprueft und nicht geglaubt.
 */
final readonly class MyReminders
{
    /** So viele kommende stehen in der Klappe der Kopfzeile. */
    public const int SHOWN = 5;

    /**
     * Und so viele vergangene.
     *
     * Weniger, weil sie nichts mehr ankuendigen — aber nicht keine: die
     * Klappe ist die einzige Stelle, an der sich eine Erinnerung wegnehmen
     * laesst. Wer nur die kommenden zeigte, machte jede Erinnerung mit ihrem
     * Termin unloeschbar. Wer eine wegnimmt, bekommt die naechstaeltere zu
     * sehen; so ist jede erreichbar, auch die vom Januar.
     */
    public const int KEPT = 3;

    /** Das Ausrufezeichen setzt die Sekunden zurueck; getippt werden sie nie. */
    private const string ISO = '!Y-m-d H:i';

    /** Ohne Uhrzeit gilt der Morgen. */
    private const string MORNING = '09:00';

    public function __construct(
        private ReminderRepository $reminders,
        private WhoIsHere $who,
        private ClockInterface $clock,
    ) {
    }

    /**
     * Was in der Klappe steht: das Naechste und das zuletzt Gewesene.
     *
     * Zwei zaehlende Abfragen ueber einen Index, und zwar auf jeder Seite —
     * die Kopfzeile steht ueberall. Das ist billiger, als sich die Liste zu
     * merken und drei Auffrischungspunkte dafuer zu pflegen.
     *
     * @return array{next: list<Reminder>, past: list<Reminder>}
     */
    public function inTheDrawer(): array
    {
        $me = ($this->who)()->id;
        $now = $this->clock->now();

        return [
            'next' => $this->reminders->nextFor($me, $now, self::SHOWN),
            'past' => $this->reminders->recentFor($me, $now, self::KEPT),
        ];
    }

    /**
     * Anlegen — oder sagen, warum nicht.
     *
     * Gibt den Uebersetzungsschluessel des Einwands zurueck, sonst `null`.
     * Keine Ausnahme: ein leerer Betreff ist Eingabe und kein
     * Programmierfehler, und eine Fehlerseite waere die falsche Antwort auf
     * einen Tippfehler.
     */
    public function add(string $subject, string $note, string $day, string $time): ?string
    {
        if (null === Trimmed::orNull($subject)) {
            return 'reminder.error.subject';
        }

        $due = self::momentOf($day, $time);

        if (null === $due) {
            return 'reminder.error.when';
        }

        $this->reminders->save(new Reminder(($this->who)()->id, $subject, $note, $due));

        return null;
    }

    /**
     * Loeschen, wenn sie mir gehoert.
     *
     * Was es nicht gibt und was mir nicht gehoert, ist dieselbe Antwort: eine
     * Unterscheidung waere die Auskunft, dass es diese Erinnerung gibt.
     */
    public function remove(string $id): bool
    {
        $reminder = $this->reminders->byId($id);

        if (null === $reminder || !$reminder->belongsTo(($this->who)()->id)) {
            return false;
        }

        $this->reminders->remove($reminder);

        return true;
    }

    /**
     * Tag und Uhrzeit zu einem Zeitpunkt — oder `null`.
     *
     * Streng geparst und danach gegengelesen, aus demselben Grund wie in
     * {@see \App\Shared\Time\DateInput}: `new DateTimeImmutable()` rechnet
     * ueberlaufende Angaben still um, und aus dem 30. Februar wird der 2.
     * Maerz. Wer den Tag nicht wiedererkennt, den er getippt hat, soll eine
     * Meldung bekommen und kein anderes Datum.
     *
     * Ohne Uhrzeit gilt der Morgen: wer nur einen Tag angibt, meint den Tag
     * und nicht Mitternacht, und ein Termin um 0:00 Uhr sieht aus wie ein
     * Fehler.
     */
    private static function momentOf(string $day, string $time): ?DateTimeImmutable
    {
        $date = Trimmed::orNull($day);

        if (null === $date) {
            return null;
        }

        $clock = Trimmed::orNull($time) ?? self::MORNING;
        $at = DateTimeImmutable::createFromFormat(self::ISO, $date.' '.$clock);

        return false !== $at && $at->format('Y-m-d H:i') === $date.' '.$clock ? $at : null;
    }
}
