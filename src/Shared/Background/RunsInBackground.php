<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Shared\Background;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Eine Arbeit, die von selbst passiert — ohne dass jemand eine Seite aufruft.
 *
 * Abgelaufene Anhaenge loeschen, faellige Mails verschicken, alte
 * Protokollzeilen wegraeumen, Plugin-Prozesse am Laufen halten. Dasselbe
 * Muster wie bei Suche und Kennzahlen: das Modul meldet seine Arbeit an, der
 * Hintergrundlauf fragt, wer sich gemeldet hat. **Kein Modul haengt vom
 * Hintergrundlauf ab**, und der Hintergrundlauf kennt kein Modul beim Namen.
 *
 * **Nicht beim Seitenaufruf.** Was still beim naechsten Aufruf passiert,
 * passiert genau dann nicht, wenn niemand aufruft — und die Mail an einen
 * Mieter wartete auf den naechsten Verwalter, der sich anmeldet.
 */
#[AutoconfigureTag('background.job')]
interface RunsInBackground
{
    /** Ein kurzer Name fuer das Protokoll des Containers. */
    public function name(): string;

    /** Wie viele Sekunden mindestens zwischen zwei Laeufen liegen. */
    public function everySeconds(): int;

    public function run(): void;
}
