<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Tests\Module\Audit\Fixture;

use App\Shared\Audit\AuditAction;
use App\Shared\Audit\RecordsActions;
use RuntimeException;

/**
 * Ein Protokoll, das auf Kommando scheitert.
 *
 * Nur fuer eine Frage da, und die laesst sich anders nicht stellen: liegen
 * Rechtevergabe und Protokollzeile in derselben Transaktion? Ob sie es tun,
 * zeigt sich erst, wenn das Schreiben der Zeile scheitert — gelingt beides,
 * sieht man keinen Unterschied.
 *
 * Es scheitert nicht von selbst, sondern erst nach {@see failNext()}. Jeder
 * Anmeldevorgang der Testreihe vergibt Rechte; ein Protokoll, das immer
 * scheitert, liesse keinen Test mehr laufen.
 */
final class ProtocolThatCanFail implements RecordsActions
{
    private bool $armed = false;

    public function __construct(private readonly RecordsActions $real)
    {
    }

    /** Der naechste Eintrag schlaegt fehl — danach ist wieder Ruhe. */
    public function failNext(): void
    {
        $this->armed = true;
    }

    public function note(AuditAction $action, string $record, string $recordId, string $label = ''): void
    {
        if ($this->armed) {
            $this->armed = false;

            throw new RuntimeException('Das Protokoll ist voll.');
        }

        $this->real->note($action, $record, $recordId, $label);
    }
}
