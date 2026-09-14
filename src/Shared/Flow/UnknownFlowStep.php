<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Shared\Flow;

use InvalidArgumentException;

final class UnknownFlowStep extends InvalidArgumentException
{
    public static function named(string $key, string $flowId): self
    {
        return new self(\sprintf('Der Ablauf "%s" hat keinen Schritt "%s".', $flowId, $key));
    }
}
