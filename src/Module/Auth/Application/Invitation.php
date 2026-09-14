<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Auth\Application;

use App\Module\Auth\Domain\User;

/**
 * Eine gerade ausgestellte Einladung.
 *
 * Der Link steht hier genau einen Augenblick lang zur Verfuegung — danach
 * gibt es ihn nirgends mehr, weil nur sein Hash gespeichert wird. Eine
 * Installation ohne Mailserver muss ihn deshalb sofort anzeigen; ihn
 * aufzubewahren, um ihn spaeter zu zeigen, hiesse einen benutzbaren
 * Schluessel im Klartext zu lagern.
 */
final readonly class Invitation
{
    public function __construct(
        public User $user,
        public string $link,
        public bool $wasSent,
    ) {
    }
}
