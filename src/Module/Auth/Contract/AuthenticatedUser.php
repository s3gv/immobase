<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Auth\Contract;

/**
 * Was andere Module ueber einen Benutzer sehen duerfen.
 *
 * Bewusst nur Primitive: ein fremdes Modul soll weder die Entity noch die
 * Wertobjekte dieses Moduls kennen muessen.
 */
final readonly class AuthenticatedUser
{
    public function __construct(
        public string $id,
        public int $number,
        public string $email,
        public string $displayName,
        public string $jobTitle,
        public bool $isActive,
        public bool $isAdministrator,
    ) {
    }
}
