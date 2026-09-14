<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Dunning\Application;

use App\Module\Dunning\Domain\Claim;
use App\Module\Dunning\Domain\ClaimRepository;
use App\Module\Finance\Contract\AdvanceDirectory;
use DateTimeImmutable;

/**
 * Den offenen Betrag neu aus den Finanzen lesen.
 *
 * Nur bei einer Forderung aus einer Vorauszahlung: dort besitzen die Finanzen
 * die Zahl. Eine eingetragene Forderung steht, wo jemand sie hingeschrieben
 * hat.
 *
 * Eine Mahnung, die mehr fordert als offen ist, ist ein Rechtsproblem und
 * keine Unschaerfe — darum wird vor jedem Blick auf den Entwurf und noch
 * einmal beim Ausstellen nachgelesen.
 */
final readonly class RereadTheArrears
{
    public function __construct(
        private AdvanceDirectory $advances,
        private ClaimRepository $claims,
    ) {
    }

    public function of(Claim $claim, DateTimeImmutable $on): void
    {
        if (!$claim->source()->isFromAnAdvance() || !$claim->arrears()->isOpen()) {
            return;
        }

        foreach ($this->advances->overdueOn($on) as $payment) {
            if ($payment->paymentId === $claim->source()->originId()) {
                $claim->nowOpen($payment->expected->minus($payment->received), $on);
                $this->claims->save($claim);

                return;
            }
        }

        // Nicht mehr ueberfaellig heisst: bezahlt. Der Vorgang endet, und die
        // Zinsen bleiben stehen, wo sie stehen.
        $claim->settle($on);
        $this->claims->save($claim);
    }
}
