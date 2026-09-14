<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Billing\UserInterface\Twig;

use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * `{{ pending_corrections() }}` fuer das Menue.
 *
 * Eine faellige Korrektur ist die einzige Meldung dieses Moduls, die von
 * selbst entsteht: niemand hat sie angelegt, sie ergibt sich daraus, dass
 * jemand anderswo eine Zahl geaendert hat. Wer nicht zufaellig auf die
 * Abrechnungsuebersicht geht, erfuehre davon nichts — und die Frist laeuft.
 *
 * Gerechnet wird hier nichts: die Zahl kommt aus {@see CorrectionBadge} und
 * damit aus der Sitzung. Was eine Zahl im Menue kostet, zahlt jede Seite —
 * auch die, auf denen niemand hinsieht.
 */
final class PendingCorrections extends AbstractExtension
{
    public function __construct(private readonly CorrectionBadge $badge)
    {
    }

    public function getFunctions(): array
    {
        return [new TwigFunction('pending_corrections', $this->badge->count(...))];
    }
}
