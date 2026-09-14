<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Auth\Domain\Rbac;

use RuntimeException;

/**
 * Nach dieser Aenderung koennte niemand mehr Benutzer verwalten.
 *
 * Wird geworfen, um eine bereits ausgefuehrte Aenderung zurueckzunehmen: bei
 * der Matrix laesst sich die Frage erst mit dem neuen Stand beantworten — wer
 * `users.edit` behaelt, haengt an allen Haekchen zugleich.
 */
final class NoManagerLeft extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('Nach dieser Änderung könnte niemand mehr Benutzer verwalten.');
    }
}
