<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Dunning\Contract;

/**
 * Was andere vom Mahnwesen wissen duerfen.
 *
 * Eine Zahl fuer das Abzeichen am Menuepunkt und die Tafel auf der
 * Finanzen-Uebersicht. Mehr braucht draussen niemand — und mehr zu geben
 * hiesse, die Liste ein zweites Mal zu bauen.
 */
interface DunningOverview
{
    public function pressure(): DunningPressure;
}
