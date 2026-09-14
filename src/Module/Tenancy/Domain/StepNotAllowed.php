<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Tenancy\Domain;

use RuntimeException;

/**
 * Die Stufe passt nicht in ihre Staffel.
 *
 * Zwei Faelle, und beide gelten fuer jede Staffel am Mietverhaeltnis: sie
 * liegt vor dem Mietbeginn — dann gaebe es einen Wert fuer eine Zeit ohne
 * Mietverhaeltnis. Oder es gibt zum selben Tag schon eine — dann waere nicht
 * entscheidbar, welche gilt.
 *
 * Die Bezeichnung kommt von aussen, weil dieselbe Regel zweierlei meint: eine
 * Mietstufe und einen Haushaltseintrag.
 */
final class StepNotAllowed extends RuntimeException
{
    public static function beforeTheStart(string $what): self
    {
        return new self($what.' kann nicht vor dem Mietbeginn liegen.');
    }

    public static function twiceOnTheSameDay(string $what): self
    {
        // „Es gibt bereits …" braeuchte den Akkusativ und damit eine zweite
        // Form je Bezeichnung. Der Satzbau hier kommt mit dem Nominativ aus.
        return new self($what.' liegt zu diesem Datum bereits vor.');
    }
}
