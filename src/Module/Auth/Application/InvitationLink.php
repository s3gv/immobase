<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Auth\Application;

/**
 * Baut aus einem Schluessel die vollstaendige Adresse.
 *
 * Eine Schnittstelle, weil die Umsetzung den Routing-Generator braucht — und
 * der gehoert nicht in die Anwendungsschicht.
 */
interface InvitationLink
{
    public function __invoke(string $token): string;
}
