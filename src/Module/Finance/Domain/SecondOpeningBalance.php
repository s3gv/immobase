<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Finance\Domain;

use RuntimeException;

/**
 * Einen Anfangsbestand gibt es hoechstens einmal.
 *
 * Ein zweiter waere keine Bewegung, sondern eine Korrektur — und die sagt
 * man besser, statt sie als Bestand zu tarnen.
 */
final class SecondOpeningBalance extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('Für dieses Objekt ist schon ein Anfangsbestand erfasst.');
    }
}
