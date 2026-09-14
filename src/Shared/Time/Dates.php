<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Shared\Time;

use DateTimeInterface;

/**
 * Ein Datum so schreiben, wie es die jeweilige Sprache liest.
 *
 * Deutsch „31.12.2026", englisch „31 Dec 2026". Der Monat steht im Englischen
 * ausgeschrieben, weil „12/31" und „31/12" beide gelaeufig sind und sich
 * widersprechen — und am Bildschirm nicht dabeisteht, welche Variante gerade
 * gilt. Ein Datum, das man falsch lesen kann, ist schlimmer als eines, das
 * drei Zeichen mehr braucht.
 *
 * Bewusst ohne die intl-Erweiterung, dieselbe Ueberlegung wie bei den Zahlen:
 * das haelt die Selbst-Installation frei von einer weiteren PHP-Erweiterung.
 *
 * Fuer die Eingabe gibt es das hier nicht — dort steht `<input type="date">`,
 * und der Browser bringt Kalender und Landesformat selbst mit.
 */
final class Dates
{
    /** Englische Monatskuerzel; die deutschen braucht das Zahlenformat nicht. */
    private const array MONTHS = [
        'Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun',
        'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec',
    ];

    private function __construct()
    {
    }

    public static function format(DateTimeInterface $date, string $locale): string
    {
        // Deutsch ist die Hauptsprache und ueberall sonst der Rueckfall.
        if ('en' !== strtolower(substr($locale, 0, 2))) {
            return $date->format('d.m.Y');
        }

        return $date->format('j').' '.self::MONTHS[(int) $date->format('n') - 1].' '.$date->format('Y');
    }

    /**
     * Datum und Uhrzeit — fuer Vorgaenge, bei denen die Minute zaehlt.
     *
     * Eine Nachricht im Gespraech steht nicht „am 31.12." da, sondern „um
     * 14:05": zwei Nachrichten desselben Tages waeren sonst nicht zu ordnen.
     * Englisch mit Halbtagesangabe, deutsch mit vierundzwanzig Stunden — so
     * liest es jede Seite, wie sie es gewohnt ist.
     */
    public static function formatWithTime(DateTimeInterface $date, string $locale): string
    {
        $day = self::format($date, $locale);

        return 'en' === strtolower(substr($locale, 0, 2))
            ? $day.', '.$date->format('g:i a')
            : $day.', '.$date->format('H:i');
    }
}
