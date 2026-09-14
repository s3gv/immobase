<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Dashboard\Domain;

use DateTimeImmutable;

interface ReminderRepository
{
    public function save(Reminder $reminder): void;

    public function remove(Reminder $reminder): void;

    public function byId(string $id): ?Reminder;

    /**
     * Die Erinnerungen eines Menschen in einem Zeitraum, die fruehesten zuerst.
     *
     * Immer mit Besitzer: es gibt keine Frage an diese Ablage, die alle
     * Erinnerungen aller Benutzer meint. Waere der Besitzer optional, gaebe
     * es genau einen Aufruf, der ihn eines Tages weglaesst.
     *
     * @return list<Reminder>
     */
    public function between(string $userId, DateTimeImmutable $from, DateTimeImmutable $to): array;

    /**
     * Die naechsten faelligen, hoechstens `$limit`.
     *
     * @return list<Reminder>
     */
    public function nextFor(string $userId, DateTimeImmutable $after, int $limit): array;

    /**
     * Die zuletzt faellig gewordenen, die juengste zuerst.
     *
     * Damit eine Erinnerung nicht mit ihrem Termin unloeschbar wird: die
     * Klappe der Kopfzeile ist die einzige Stelle, an der sie sich wegnehmen
     * laesst, und was dort nicht steht, bleibt fuer immer stehen.
     *
     * @return list<Reminder>
     */
    public function recentFor(string $userId, DateTimeImmutable $before, int $limit): array;
}
