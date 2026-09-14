<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Billing\Domain;

use RuntimeException;

/**
 * Berichtigt wird eine Auskunft, die jemand bekommen hat.
 *
 * Zwei Faelle, und beide enden sonst mit zwei Auskuenften ueber denselben
 * Stichtag:
 *
 * * Ein **Entwurf** wird bearbeitet und nicht berichtigt. Aus ihm eine zweite
 *   Fassung zu machen hiesse, neben der offenen eine zweite offene zu haben —
 *   beide bearbeitbar, beide herausgebbar.
 * * Eine **ueberholte Fassung** wird nicht noch einmal berichtigt. Die
 *   juengste ist die, die gilt; an einer aelteren weiterzuschreiben hiesse,
 *   eine Auskunft fortzusetzen, die es so nicht mehr gibt.
 */
final class AssetReportCannotBeCorrected extends RuntimeException
{
    public static function itIsStillADraft(): self
    {
        return new self('billing.report.error.draft_not_corrected');
    }

    public static function itIsNotTheLatestVersion(): self
    {
        return new self('billing.report.error.outdated');
    }
}
