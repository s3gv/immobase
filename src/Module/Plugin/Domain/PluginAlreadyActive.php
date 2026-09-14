<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Plugin\Domain;

use RuntimeException;

/** Jemand war schneller: das Plugin ist schon aktiviert. */
final class PluginAlreadyActive extends RuntimeException
{
    public function __construct(string $name)
    {
        parent::__construct(\sprintf('„%s" ist bereits aktiviert.', $name));
    }
}
