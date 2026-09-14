<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Billing\Application;

use App\Module\Billing\Domain\Distribution;

/**
 * Wie ein Verteilerschluessel im Plan erklaert wird.
 *
 * Fast immer genauso wie in der Abrechnung — „nach Wohnfläche" heisst im Plan
 * dasselbe. Eine Sorte ist anders: **erfasster Verbrauch**. Fuer ein Jahr, das
 * noch nicht stattgefunden hat, gibt es keinen; verteilt wird nach dem des
 * Vorjahres. Ein Blatt, auf dem „nach erfasstem Verbrauch" stuende, behauptete
 * eine Messung, die es nicht gibt.
 *
 * Eine eigene Klasse und keine zwei Zeilen im Aufrufer, weil es die Antwort an
 * zwei Stellen braucht: fuer Zeilen, die verteilt wurden, und fuer die, die
 * nichts zu verteilen hatten.
 */
final readonly class WhatWasPlanned
{
    public function explanationOf(string $keyKind): string
    {
        return 'metered' === $keyKind ? 'billing.plan.key.metered' : 'billing.key.'.$keyKind;
    }

    public function explain(Distribution $distribution, string $keyKind): Distribution
    {
        return 'metered' === $keyKind
            ? $distribution->explainedBy('billing.plan.key.metered')
            : $distribution;
    }
}
