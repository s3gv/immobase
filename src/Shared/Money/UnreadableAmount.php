<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Shared\Money;

use InvalidArgumentException;

final class UnreadableAmount extends InvalidArgumentException
{
    public static function of(string $input): self
    {
        return new self(\sprintf('"%s" ist kein lesbarer Geldbetrag.', $input));
    }

    public static function outOfRange(string $input): self
    {
        return new self(\sprintf('"%s" liegt außerhalb des darstellbaren Bereichs.', $input));
    }
}
