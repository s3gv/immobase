<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Portal\Domain;

/**
 * Wie ein Vorschlag ausgegangen ist.
 *
 * **Einmal entscheidbar.** Ein Vorschlag, der sich nach dem Uebernehmen noch
 * einmal uebernehmen liesse, schriebe den alten Stand ein zweites Mal — und
 * zwar ueber das, was inzwischen dasteht.
 */
enum ProposalDecision: string
{
    case Pending = 'pending';
    case Accepted = 'accepted';
    case Rejected = 'rejected';

    public function labelKey(): string
    {
        return 'change.decision.'.$this->value;
    }

    public function isOpen(): bool
    {
        return self::Pending === $this;
    }
}
