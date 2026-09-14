<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace Reporting;

/** Woher der Bestand kommt — im Betrieb der Core, im Test etwas Vorgefertigtes. */
interface Source
{
    /**
     * @return iterable<array<string, mixed>>
     */
    public function everything(string $resource): iterable;
}
