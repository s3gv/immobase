<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Settings\UserInterface\Twig;

use App\Module\Settings\Contract\ApplicationSettings;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Ob die Installation auf Bewegung verzichtet.
 *
 * Die Angabe haengt an der Huelle und nicht an einer Seite: die
 * Hintergrundkarte liegt in `base.html.twig` und waere sonst durch jeden
 * Controller zu reichen — an fuenf Stellen zu tun und an der sechsten zu
 * vergessen.
 */
final class MotionSetting extends AbstractExtension
{
    public function __construct(private readonly ApplicationSettings $settings)
    {
    }

    public function getFunctions(): array
    {
        return [new TwigFunction('motion_is_reduced', $this->isReduced(...))];
    }

    public function isReduced(): bool
    {
        return $this->settings->bool(ApplicationSettings::REDUCED_MOTION, false);
    }
}
