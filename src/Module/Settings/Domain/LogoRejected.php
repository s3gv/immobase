<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Settings\Domain;

use DomainException;

/**
 * Die Datei taugt nicht als Logo.
 *
 * Eine Lage mit drei Erklaerungen und deshalb eine Klasse: kein PNG, falsche
 * Masze, zu grosz. Der Grund reist als Uebersetzungsschluessel mit — wer die
 * Absage liest, soll wissen, was zu tun ist, und „ungueltige Datei" sagt
 * das nicht.
 */
final class LogoRejected extends DomainException
{
    private function __construct(public readonly string $reasonKey, string $message)
    {
        parent::__construct($message);
    }

    public static function notAPng(): self
    {
        return new self('settings.error.logo_not_png', 'Das Logo muss ein PNG sein.');
    }

    public static function wrongSize(int $width, int $height): self
    {
        return new self(
            'settings.error.logo_size',
            \sprintf('Das Logo muss 600 × 200 messen, dieses misst %d × %d.', $width, $height),
        );
    }

    public static function tooBig(): self
    {
        return new self('settings.error.logo_too_big', 'Das Logo darf höchstens 1 MB groß sein.');
    }

    public static function unreadable(): self
    {
        return new self('settings.error.logo_unreadable', 'Die Datei ließ sich nicht lesen.');
    }
}
