<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Auth\Contract;

/**
 * Fuer wen das angemeldete Konto spricht.
 *
 * Das Portal fragt hier, statt die Klasse des angemeldeten Benutzers zu
 * kennen. Null ohne Anmeldung und bei Konten, die fuer niemanden sprechen.
 */
interface SignedInParty
{
    public function partyId(): ?string;
}
