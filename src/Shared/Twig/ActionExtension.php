<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Shared\Twig;

use App\Shared\Ui\Action;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Macht das Aktionsvokabular in Vorlagen verfuegbar.
 *
 * Ein unbekannter Name schlaegt hier fehl statt still nichts zu zeichnen: ein
 * Knopf, der aus einem Tippfehler heraus unsichtbar bleibt, faellt sonst erst
 * auf, wenn ihn jemand vermisst.
 */
final class ActionExtension extends AbstractExtension
{
    public function getFunctions(): array
    {
        return [new TwigFunction('ui_action', Action::from(...))];
    }
}
