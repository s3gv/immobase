<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Shared\Write;

use DateTimeImmutable;

/**
 * Etwas wurde angelegt, geaendert oder geloescht.
 *
 * **Mit der Entitaet und nicht nur mit ihrem Namen.** Wer davon erfaehrt,
 * braucht Verschiedenes: das Protokoll eine Bezeichnung, die Zustellung eine
 * Ressource und eine Kennung. Wuerde das Signal sich auf das Kleinste
 * einigen, muesste der Naechste, der etwas anderes braucht, es wieder
 * aufbohren.
 *
 * Beobachter lesen die Entitaet und aendern sie nicht: sie kommen nach dem
 * Speichern, und was sie hier schreiben wuerden, ginge nicht mehr mit.
 */
final readonly class WriteHappened
{
    public const string CREATED = 'created';
    public const string UPDATED = 'updated';
    public const string DELETED = 'deleted';

    public function __construct(
        public object $entity,
        public string $action,
        public DateTimeImmutable $at,
    ) {
    }
}
