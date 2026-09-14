<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Plugin\Contract;

/**
 * Wer da anklopft — und was er lesen darf.
 *
 * Ein Plugin ist kein Benutzer: es hat keine Sitzung, keine Rollen und
 * handelt fuer niemanden ausser sich selbst. Es traegt deshalb genau zwei
 * Dinge mit sich: seinen Namen und die Bereiche, die jemand ihm bei der
 * Aktivierung freigegeben hat.
 */
final readonly class PluginIdentity
{
    /**
     * @param list<string> $reads Rechteschluessel, die bestaetigt wurden
     */
    public function __construct(
        public string $name,
        public array $reads,
    ) {
    }

    public function mayRead(string $permission): bool
    {
        return \in_array($permission, $this->reads, true);
    }
}
