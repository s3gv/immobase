<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Tenancy\Domain;

/**
 * Wann die Miete faellig ist.
 *
 * „Dritter Werktag" ist die gesetzliche Vorgabe fuer Wohnraum (§ 556b BGB)
 * und deshalb die Voreinstellung. Die beiden anderen kommen in aelteren
 * Vertraegen vor, und ein Vertrag wird abgeschrieben und nicht korrigiert.
 */
enum PaymentDue: string
{
    case ThirdWorkingDay = 'third_working_day';
    case MonthStart = 'month_start';
    case MonthEnd = 'month_end';

    public function labelKey(): string
    {
        return 'tenancy.payment.due.'.$this->value;
    }
}
