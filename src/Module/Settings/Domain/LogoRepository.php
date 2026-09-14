<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Settings\Domain;

/**
 * Das Logo der Installation — es gibt hoechstens eines.
 */
interface LogoRepository
{
    public function current(): ?Logo;

    public function save(Logo $logo): void;

    public function remove(Logo $logo): void;
}
