<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Dunning\Domain;

use RuntimeException;

/**
 * Die Stufen folgen aufeinander.
 *
 * Eine letzte Mahnung, der keine erste vorausging, ist keine letzte — und
 * eine zweite Zahlungserinnerung nach einer Mahnung waere ein Rueckschritt,
 * den der Empfaenger zu Recht als Nachgeben liest.
 */
final class LevelOutOfOrder extends RuntimeException
{
    public static function itDoesNotFollow(): self
    {
        return new self('dunning.error.level_out_of_order');
    }
}
