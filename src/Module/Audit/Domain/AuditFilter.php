<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Audit\Domain;

use App\Shared\Audit\AuditAction;
use App\Shared\Text\Trimmed;

/**
 * Wonach das Protokoll eingeschraenkt wird.
 *
 * Eine leere Angabe schraenkt nicht ein, eine unbekannte auch nicht: was in
 * der Adresszeile steht, ist Eingabe und kein Programmierfehler.
 */
final readonly class AuditFilter
{
    private function __construct(
        public ?AuditAction $action,
        public ?string $search,
    ) {
    }

    public static function none(): self
    {
        return new self(null, null);
    }

    public static function of(?string $action, ?string $search): self
    {
        $term = Trimmed::orNull($search ?? '');

        return new self(
            AuditAction::tryFrom($action ?? ''),
            null === $term ? null : mb_strtolower($term),
        );
    }

    public function isEmpty(): bool
    {
        return null === $this->action && null === $this->search;
    }
}
