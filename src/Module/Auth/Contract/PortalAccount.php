<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Auth\Contract;

use DateTimeImmutable;

/**
 * Der Portalzugang einer Partei, wie die Stammdaten ihn sehen.
 *
 * Nur Primitive: das Stammdatenmodul soll `Auth\Domain\User` nicht kennen
 * muessen, um einen Knopf zu zeichnen.
 */
final readonly class PortalAccount
{
    public function __construct(
        public string $id,
        public string $email,
        /** eingeladen, aktiv oder gesperrt — als Uebersetzungsschluessel */
        public string $statusKey,
        /**
         * Kann sich jetzt anmelden — also: nicht gesperrt **und** mit Passwort.
         *
         * Ein frisch eingeladenes Konto kann es noch nicht; das heisst aber
         * nicht, dass es entzogen waere.
         */
        public bool $canSignIn,
        /** Entzogen. Davon zu unterscheiden, sonst verschwindet der Knopf zum Entziehen. */
        public bool $isRevoked,
        public ?DateTimeImmutable $lastSignInAt,
    ) {
    }
}
