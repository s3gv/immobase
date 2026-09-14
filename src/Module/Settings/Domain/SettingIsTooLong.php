<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Settings\Domain;

use DomainException;

/**
 * Eine Angabe, die nicht in die Ablage passt.
 *
 * Die Oberflaeche faengt das vorher ab und sagt, welches Feld gemeint ist;
 * diese Absage ist die letzte Linie. Sie traegt den Schluessel mit, damit im
 * Fehlerfall dasteht, woran es lag.
 */
final class SettingIsTooLong extends DomainException
{
    private function __construct(public readonly string $key, string $message)
    {
        parent::__construct($message);
    }

    public static function at(string $key): self
    {
        return new self($key, \sprintf(
            'Die Einstellung „%s" fasst höchstens %d Zeichen.',
            $key,
            Setting::MOST_CHARACTERS,
        ));
    }
}
