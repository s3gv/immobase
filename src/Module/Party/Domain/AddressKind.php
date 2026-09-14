<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Party\Domain;

enum AddressKind: string
{
    case Street = 'street';
    case PoBox = 'poBox';

    public function labelKey(): string
    {
        return 'party.address_kind.'.$this->value;
    }
}
