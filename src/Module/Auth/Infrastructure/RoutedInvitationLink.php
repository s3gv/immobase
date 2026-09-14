<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Auth\Infrastructure;

use App\Module\Auth\Application\InvitationLink;
use App\Shared\Http\PublicUrls;

/**
 * Die Einladungsadresse, vollstaendig mit Schema und Host.
 *
 * Absolut und nicht relativ: der Link steht in einer E-Mail, und dort gibt es
 * keine Seite, auf die sich "/einladung/..." beziehen koennte. Woher der Host
 * kommt, entscheidet die Routing-Konfiguration — beim Versand aus der Konsole
 * ist das `default_uri`, im Request der Request selbst.
 */
final readonly class RoutedInvitationLink implements InvitationLink
{
    public function __construct(private PublicUrls $urls)
    {
    }

    public function __invoke(string $token): string
    {
        return $this->urls->absolute('app_invitation', ['token' => $token]);
    }
}
