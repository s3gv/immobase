<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Shared\Audit;

/**
 * Eine Zeile fuer das Protokoll, von Hand.
 *
 * **Fuer die Schreibwege, die an der ORM vorbeigehen.** Das Protokoll haengt
 * an Doctrines Lebenszyklus und sieht damit jedes `persist()` und jedes
 * `remove()` — aber weder ein `DELETE` ueber DBAL noch eine DQL-Massenloeschung
 * loest ein Ereignis aus. Wer so schreibt, meldet es hier.
 *
 * Gebraucht wird das selten und ausgerechnet dort, wo es am meisten zaehlt:
 * die Zuordnungstabellen der Rechte halten Zeichenketten und keine
 * Entitaeten, und eine Rechtevergabe ist der Vorgang, bei dem am ehesten
 * jemand wissen will, wer ihn ausgeloest hat.
 *
 * Liegt in Shared, damit ein Modul melden kann, ohne das Protokoll zu kennen.
 */
interface RecordsActions
{
    /**
     * @param string $record   die Art, ohne Namensraum — „Role", „User"
     * @param string $recordId die Kennung, an der sich zwei Zeilen erkennen
     * @param string $label    woran man den Datensatz erkennt
     */
    public function note(AuditAction $action, string $record, string $recordId, string $label = ''): void;
}
