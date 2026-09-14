<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Billing\Domain;

/**
 * Wo eine Abrechnung steht.
 *
 * Zwei Zustaende und kein dritter: solange sie ein Entwurf ist, gehoert sie
 * niemandem und laesst sich verwerfen. Mit der Freigabe wird sie zu Post, die
 * jemand bekommt — ab da ist sie unumkehrbar.
 */
enum StatementStatus: string
{
    case Draft = 'draft';
    case Released = 'released';

    public function labelKey(): string
    {
        return 'billing.status.'.$this->value;
    }

    public function isDraft(): bool
    {
        return self::Draft === $this;
    }
}
