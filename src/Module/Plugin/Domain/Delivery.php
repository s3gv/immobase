<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Plugin\Domain;

use DateTimeImmutable;

/**
 * Eine Zustellung, die noch aussteht.
 *
 * **Eine Ablage und kein Aufruf.** Waere der Webhook Teil des Speicherns,
 * haetten die Geschwindigkeit und die Erreichbarkeit eines fremden Prozesses
 * ploetzlich etwas damit zu tun, ob eine Kostenposition gespeichert werden
 * kann. Stattdessen legt der Core eine Zeile ab, und ein eigener Lauf traegt
 * sie aus.
 *
 * Als schlichtes Wertobjekt und keine Entitaet: die Zeilen entstehen mitten
 * im `postFlush` und gehen ueber einfache INSERTs hinaus — durch die
 * Arbeitseinheit duerfen sie nicht, sonst protokollierten sie sich selbst.
 */
final readonly class Delivery
{
    public function __construct(
        public string $id,
        public string $plugin,
        public string $event,
        public string $payload,
        public DateTimeImmutable $createdAt,
        public DateTimeImmutable $deliverAt,
        public int $attempts = 0,
        public string $lastError = '',
    ) {
    }
}
