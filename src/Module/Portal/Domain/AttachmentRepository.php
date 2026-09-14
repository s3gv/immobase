<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Portal\Domain;

use DateTimeImmutable;

/**
 * Die Anhaenge — und ihr Verfallsdatum.
 */
interface AttachmentRepository
{
    public function byId(string $id): ?Attachment;

    /**
     * Die abgelaufenen loeschen — und sagen, wie viele es waren.
     *
     * Eine Anweisung und keine Liste: es koennen viele sein, und sie einzeln
     * zu laden hiesse, ihre Bytes in den Speicher zu holen, um sie danach
     * wegzuwerfen.
     */
    public function forgetExpired(DateTimeImmutable $on): int;
}
