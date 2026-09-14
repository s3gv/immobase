<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Finance\Domain;

use App\Shared\Money\Money;
use DateTimeImmutable;

/**
 * Eine Faelligkeit, wie sie sich aus der Staffel ergibt.
 *
 * Noch kein Datensatz — nur die Rechnung „an diesem Tag war so viel faellig".
 * Was daraus wird, entscheidet der Abgleich mit dem, was schon dasteht.
 */
final readonly class Due
{
    public function __construct(
        public AdvanceKind $kind,
        public DateTimeImmutable $dueOn,
        public Money $expected,
    ) {
    }

    /** Der Schluessel, unter dem sich Soll und Bestand wiederfinden. */
    public function key(): string
    {
        return $this->kind->value.'|'.$this->dueOn->format('Y-m-d');
    }
}
