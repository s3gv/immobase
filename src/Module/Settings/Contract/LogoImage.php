<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Settings\Contract;

/**
 * Das Logo, so viel wie ein fremdes Modul davon sehen darf: die Bytes und
 * was sie sind.
 *
 * Die Maszeinheit ist fest — 600 × 200 —, und deshalb steht sie hier nicht:
 * wer das Bild in ein PDF setzt, kennt die Flaeche schon.
 */
final readonly class LogoImage
{
    public function __construct(
        public string $bytes,
        public string $contentType,
    ) {
    }
}
