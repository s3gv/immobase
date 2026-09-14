<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Shared\Bank;

use InvalidArgumentException;

final class NotACreditorId extends InvalidArgumentException
{
    public function __construct()
    {
        parent::__construct('Das ist keine gültige Gläubiger-Identifikationsnummer.');
    }
}
