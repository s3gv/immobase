<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Auth\Contract;

/**
 * Oeffentliche Abfrage-Schnittstelle des Auth-Moduls.
 *
 * Dies ist die einzige Flaeche, ueber die andere Module Benutzerdaten lesen.
 */
interface UserDirectory
{
    public function byEmail(string $email): ?AuthenticatedUser;

    /**
     * Die Konten der Verwaltung, Kennung auf Namen — ohne Portalkonten.
     *
     * @return array<string, string>
     */
    public function colleagues(): array;
}
