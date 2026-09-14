<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Shared\Todo;

use DateTimeImmutable;

/**
 * Eine Sache, die auf einen Menschen wartet.
 *
 * **Eine Meldung fuer fuenf Dinge, nicht fuenf Meldungen.** „3 Mahnungen
 * faellig" ist eine Zeile mit einem Knopf; drei Zeilen mit demselben Knopf
 * waeren dieselbe Arbeit, dreimal aufgeschrieben.
 *
 * Uebersetzt wird in der Vorlage: das Modul liefert Schluessel und Werte, und
 * die Uebersicht setzt sie in der Sprache der Anfrage zusammen. Ein Modul,
 * das fertige Saetze liefert, kann nur eine Sprache.
 */
final readonly class Todo
{
    /**
     * @param array<string, string|int> $params fuer die Uebersetzung, etwa `%count%`
     */
    public function __construct(
        public TodoKind $kind,
        public Urgency $urgency,
        public string $labelKey,
        public array $params = [],
        /** Wie viele es sind — fuer die Sortierung und fuer die Uebersetzung. */
        public int $count = 1,
        /** Wohin man sieht. Leer heisst: es gibt nichts anzusehen. */
        public string $url = '',
        /**
         * Der Knopf, mit dem man es erledigt.
         *
         * Beides oder nichts: ein Knopf ohne Ziel tut nichts, und ein Ziel
         * ohne Beschriftung ist keiner.
         */
        public string $actionKey = '',
        public string $actionUrl = '',
        /** Wann es ablaeuft — nur bei Fristen, sonst `null`. */
        public ?DateTimeImmutable $dueOn = null,
    ) {
    }

    public function hasAction(): bool
    {
        return '' !== $this->actionKey && '' !== $this->actionUrl;
    }
}
