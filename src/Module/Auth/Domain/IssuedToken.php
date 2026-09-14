<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Auth\Domain;

/**
 * Ein frisch ausgestellter Schluessel und sein Klartext.
 *
 * Die beiden gehoeren genau einen Augenblick lang zusammen: der Schluessel
 * wird gespeichert, der Klartext verschickt. Danach gibt es den Klartext
 * nirgends mehr. Ein Rueckgabewert aus zwei Teilen macht sichtbar, dass das
 * Absicht ist.
 */
final readonly class IssuedToken
{
    public function __construct(
        public SignInToken $token,
        public string $plain,
    ) {
    }
}
