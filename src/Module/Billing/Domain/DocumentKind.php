<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Billing\Domain;

/**
 * Die Schreiben dieses Moduls, als Aufzaehlung.
 *
 * Fuer die zentrale Suche: sie liefert alle fuenf in einer Gruppe, und jeder
 * Treffer muss sagen koennen, was er ist und wohin er fuehrt. Eine Gruppe je
 * Art waere fuenf Ueberschriften mit je einem Eintrag darunter — wer „2026"
 * sucht, will die Schreiben zu 2026 sehen und keine Gliederung.
 */
enum DocumentKind: string
{
    case Statement = 'statement';
    case Plan = 'plan';
    case Budget = 'budget';
    case AssetReport = 'report';

    /**
     * Wie ein einzelnes Schreiben heisst.
     *
     * Nicht die Ueberschrift der Liste: die steht in der Mehrzahl, und in
     * einer Trefferzeile stuende dann „Abrechnungen · Rosenweg · 2026".
     */
    public function labelKey(): string
    {
        return 'search.document.'.$this->value;
    }

    /** Die Route zur Einzelansicht — alle nehmen die Kennung. */
    public function route(): string
    {
        return 'app_billing_'.$this->value.'_show';
    }
}
